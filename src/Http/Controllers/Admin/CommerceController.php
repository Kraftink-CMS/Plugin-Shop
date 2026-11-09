<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Admin;

use App\Kraft\System\TableQuery;
use App\Kraft\Audit;
use App\Kraft\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Billing\DocumentMessage;
use KraftInk\Plugins\Shop\Models\Billing\Receipt;
use KraftInk\Plugins\Shop\Services\Commerce\ReceiptService;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;

class CommerceController
{
    public function index(): View
    {
        $settings = app(Settings::class);
        $rate = (int) $settings->get('shop.commerce.rate_bps', 0);

        return view('shop::admin.commerce.index', [
            'sellerName' => $settings->get('shop.commerce.seller_name', ''),
            'sellerAddress' => $settings->get('shop.commerce.seller_address', ''),
            'sellerTaxId' => $settings->get('shop.commerce.seller_tax_id', ''),
            'prefix' => $settings->get('shop.commerce.number_prefix', 'KI'),
            'taxLabel' => $settings->get('shop.commerce.tax_label', ''),
            'rate' => intdiv($rate, 100).'.'.str_pad((string) ($rate % 100), 2, '0', STR_PAD_LEFT),
            'configuration' => app(ReceiptService::class)->configuration(auth()->user()),
            'emailEnabled' => $settings->get('shop.commerce.email_enabled', true),
            'mailEnabled' => $settings->get('mail.enabled', false),
            'emailPolicies' => collect(['purchases', 'subscription_initial', 'subscription_renewals', 'refunds'])
                ->mapWithKeys(fn ($policy) => [$policy => $settings->get('shop.commerce.email_'.$policy, true)])
                ->all(),
            'messages' => DocumentMessage::query()
                ->join('shop__receipts', 'shop__receipts.id', '=', 'shop__document_messages.receipt_id')
                ->select('shop__document_messages.*', 'shop__receipts.number')
                ->orderByDesc('shop__document_messages.id')
                ->limit(30)
                ->get(),
            'documents' => TableQuery::paginate(
                Receipt::query()
                    ->join('shop__orders', 'shop__orders.id', '=', 'shop__receipts.order_id')
                    ->leftJoin(
                        'shop__document_messages',
                        'shop__document_messages.receipt_id',
                        '=',
                        'shop__receipts.id',
                    )
                    ->select(
                        'shop__receipts.*',
                        'shop__orders.reference as order_reference',
                        'shop__document_messages.status as mail_status',
                    )
                    ->orderByDesc('shop__receipts.id'),
                [
                    'number' => 'shop__receipts.number',
                    'issued_at' => 'shop__receipts.issued_at',
                    'gross_minor' => 'shop__receipts.gross_minor',
                    'mail_status' => 'shop__document_messages.status',
                    'order_reference' => 'shop__orders.reference',
                ],
                pageName: 'page',
                search: ['shop__receipts.number', 'shop__orders.reference'],
            ),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $this->normalizeStatus($request);
        $request->validate(['active_panel' => ['sometimes', Rule::in(['documents', 'identity', 'taxes', 'sending'])]]);
        $data = $request->validate([
            'seller_type' => ['required', Rule::in(['individual', 'business'])],
            'seller_name' => ['nullable', 'string', 'max:160'],
            'seller_address' => ['nullable', 'string', 'max:1500'],
            'seller_tax_id' => ['nullable', 'string', 'max:100'],
            'seller_country' => ['required', 'regex:/^[A-Z]{2}$/D'],
            'seller_siren' => ['nullable', 'regex:/^[0-9]{9}$/D'],
            'seller_siret' => ['nullable', 'regex:/^[0-9]{14}$/D'],
            'seller_legal_form' => ['nullable', 'string', 'max:100'],
            'seller_capital' => ['nullable', 'string', 'max:100'],
            'seller_registration' => ['nullable', 'string', 'max:160'],
            'seller_email' => ['nullable', 'email', 'max:254'],
            'tax_mode' => ['required', Rule::in(['none', 'exempt', 'standard'])],
            'tax_notice' => ['nullable', 'string', 'max:500'],
            'legal_notice' => ['nullable', 'string', 'max:2000'],
            'terms_url' => ['nullable', 'url:http,https', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:1500'],
            'email_enabled' => ['sometimes', 'boolean'],
            'email_purchases' => ['sometimes', 'boolean'],
            'email_subscription_initial' => ['sometimes', 'boolean'],
            'email_subscription_renewals' => ['sometimes', 'boolean'],
            'email_refunds' => ['sometimes', 'boolean'],
            'number_prefix' => ['required', 'string', 'regex:/^[A-Z0-9]{1,12}$/D'],
            'tax_label' => ['nullable', 'string', 'max:80'],
            'tax_rate' => [
                'required',
                'string',
                'regex:/^(?:100(?:[.,]0{1,2})?|(?:[0-9]|[1-9][0-9])(?:[.,][0-9]{1,2})?)$/D',
            ],
        ]);
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $data['tax_rate']), 2), 2, '');
        $data['rate_bps'] = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        unset($data['tax_rate']);
        $errors = [];
        if ($data['seller_type'] === 'business') {
            foreach (['seller_name', 'seller_address', 'seller_legal_form', 'payment_terms'] as $field) {
                if (empty(trim($data[$field] ?? ''))) {
                    $errors[$field] = __('shop::commerce.documents.required_business');
                }
            }
            if ($data['seller_country'] === 'FR') {
                if (!$this->validRegistration($data['seller_siren'] ?? '', 9)) {
                    $errors['seller_siren'] = __('shop::commerce.documents.invalid_registration');
                }
                if (
                    !$this->validRegistration($data['seller_siret'] ?? '', 14) ||
                    !str_starts_with($data['seller_siret'] ?? '', $data['seller_siren'] ?? '-')
                ) {
                    $errors['seller_siret'] = __('shop::commerce.documents.invalid_registration');
                }
            }
        }
        if (
            ($data['tax_mode'] !== 'standard' && $data['rate_bps'] !== 0) ||
            ($data['seller_type'] === 'individual' && $data['tax_mode'] !== 'none')
        ) {
            $errors['tax_rate'] = __('shop::commerce.documents.invalid_tax_mode');
        }
        if ($data['tax_mode'] === 'standard' && empty($data['seller_tax_id'])) {
            $errors['seller_tax_id'] = __('shop::commerce.documents.required_business');
        }
        if ($data['tax_mode'] === 'exempt' && empty(trim($data['tax_notice'] ?? ''))) {
            $errors['tax_notice'] = __('shop::commerce.documents.required_business');
        }
        if (
            $data['seller_type'] === 'business' &&
            $data['seller_country'] === 'FR' &&
            $data['tax_mode'] === 'none' &&
            empty(trim($data['tax_notice'] ?? ''))
        ) {
            $errors['tax_notice'] = __('shop::commerce.documents.required_business');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $data['email_enabled'] = $request->boolean('email_enabled');
        foreach (['purchases', 'subscription_initial', 'subscription_renewals', 'refunds'] as $policy) {
            $data['email_'.$policy] = $request->boolean('email_'.$policy);
        }
        DB::transaction(function () use ($data, $request) {
            foreach ($data as $key => $value) {
                app(Settings::class)->set('shop.commerce.'.$key, $value ?? '');
            }
            Audit::record('shop.commerce.configured', ['rate_bps' => $data['rate_bps']], $request->user());
        });

        return redirect()
            ->to(route('plugins.shop.admin.commerce.index').'#billing-'.$request->input('active_panel', 'identity'))
            ->with('status', __('shop::messages.feedback.saved'));
    }

    public function preview(Request $request, ReceiptService $receipts): JsonResponse
    {
        $this->normalizeStatus($request);
        $rules = [
            'seller_type' => ['required', Rule::in(['individual', 'business'])],
            'seller_country' => ['required', 'regex:/^[A-Z]{2}$/D'],
            'number_prefix' => ['required', 'string', 'regex:/^[A-Z0-9]{1,12}$/D'],
            'tax_mode' => ['required', Rule::in(['none', 'exempt', 'standard'])],
            'tax_rate' => [
                'required',
                'string',
                'regex:/^(?:100(?:[.,]0{1,2})?|(?:[0-9]|[1-9][0-9])(?:[.,][0-9]{1,2})?)$/D',
            ],
        ];
        foreach (
            [
                'seller_name' => 160,
                'seller_address' => 1500,
                'seller_email' => 254,
                'seller_tax_id' => 100,
                'seller_siren' => 9,
                'seller_siret' => 14,
                'seller_legal_form' => 100,
                'seller_capital' => 100,
                'seller_registration' => 160,
                'tax_label' => 80,
                'tax_notice' => 500,
                'payment_terms' => 1500,
                'legal_notice' => 2000,
            ] as $field => $length
        ) {
            $rules[$field] = ['nullable', 'string', 'max:'.$length];
        }
        $data = $request->validate($rules);
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $data['tax_rate']), 2), 2, '');
        $data['rate_bps'] = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        unset($data['tax_rate']);

        return response()
            ->json(['success' => true, 'data' => ['html' => $receipts->preview($request->user(), $data)]])
            ->header('Cache-Control', 'private, no-store');
    }

    private function normalizeStatus(Request $request): void
    {
        if ($request->input('seller_type') === 'individual') {
            $request->merge(['tax_mode' => 'none', 'tax_rate' => '0']);
            foreach (
                [
                    'seller_siren',
                    'seller_siret',
                    'seller_legal_form',
                    'seller_capital',
                    'seller_registration',
                    'seller_tax_id',
                    'tax_notice',
                    'payment_terms',
                ] as $field
            ) {
                $request->merge([$field => null]);
            }
        } elseif ($request->input('seller_country') !== 'FR') {
            $request->merge(['seller_siren' => null, 'seller_siret' => null]);
        }
        if (in_array($request->input('tax_mode'), ['none', 'exempt'], true)) {
            $request->merge(['tax_rate' => '0', 'seller_tax_id' => null]);
        } elseif ($request->input('tax_mode') === 'standard') {
            $request->merge(['tax_notice' => null]);
        }
    }

    private function validRegistration(string $value, int $length): bool
    {
        if (strlen($value) !== $length || !ctype_digit($value) || (int) $value === 0) {
            return false;
        }
        if ($length === 14 && str_starts_with($value, '356000000')) {
            return array_sum(str_split($value)) % 5 === 0;
        }
        $sum = 0;
        foreach (str_split(strrev($value)) as $position => $digit) {
            $number = (int) $digit * ($position % 2 === 1 ? 2 : 1);
            $sum += intdiv($number, 10) + ($number % 10);
        }

        return $sum % 10 === 0;
    }
}
