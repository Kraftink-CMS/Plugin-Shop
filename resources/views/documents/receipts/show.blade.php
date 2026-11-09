@use('App\Kraft\SiteDates')
@use('KraftInk\Plugins\Shop\Services\Commerce\ReceiptService')
<!doctype html>
<html lang="{{ $receipt['locale'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('shop::commerce.documents.' . ($receipt['document_type'] ?? $receipt['kind'])) }} {{ $receipt['number'] }}
    </title>
    <style>
        body {
            font: 14px/1.45 system-ui, sans-serif;
            color: #222;
            max-width: 900px;
            margin: 3rem auto;
            padding: 0 1.5rem
        }

        header {
            border-bottom: 2px solid #222;
            padding-bottom: 1.5rem;
            margin-bottom: 2rem
        }

        h1 {
            font-size: 1.6rem
        }

        h2 {
            font-size: 1.1rem
        }

        p {
            margin: .35rem 0
        }

        address {
            font-style: normal;
            white-space: pre-line
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin: 2rem 0
        }

        th,
        td {
            text-align: left;
            padding: .7rem;
            border-bottom: 1px solid #ddd
        }

        td:last-child,
        th:last-child {
            text-align: right
        }

        dl {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: .6rem 2rem
        }

        dd {
            margin: 0;
            text-align: right
        }

        .muted {
            color: #555;
            font-size: .9rem
        }

        @media print {
            body {
                margin: 0;
                max-width: none;
                padding: 0;
                font-size: 11px
            }

            header {
                break-inside: avoid
            }

            tr {
                break-inside: avoid
            }
        }
    </style>
</head>
<body>
    @php($dates = app(SiteDates::class))
    <header>
        @if (!empty($receipt['preview']))
            <p style="border: 2px solid currentColor; padding: 1rem; font-weight: bold">{{ __('shop::commerce.documents.preview_notice') }}</p>
        @endif
        <h1>{{ __('shop::commerce.documents.' . ($receipt['document_type'] ?? $receipt['kind'])) }} {{ $receipt['number'] }}</h1>
        <strong>{{ $receipt['seller_name'] }}</strong>
        @if ($receipt['seller_address'])
            <address>{{ $receipt['seller_address'] }}</address>
        @endif
        @if ($receipt['seller_tax_id'])
            <p>{{ __('shop::commerce.documents.tax_id') }} : {{ $receipt['seller_tax_id'] }}</p>
        @endif
        @foreach (['seller_country', 'seller_legal_form', 'seller_siren', 'seller_siret', 'seller_capital', 'seller_registration', 'seller_email'] as $field)
            @if (!empty($receipt[$field]))
                <p>{{ __('shop::commerce.documents.document_fields.' . $field) }} : {{ $field === 'seller_country' ? ReceiptService::countryName($receipt[$field], $receipt['locale']) : $receipt[$field] }}</p>
            @endif
        @endforeach
    </header>
    @if (!empty($receipt['billing']['name']))
        <section>
            <h2>{{ __('shop::commerce.documents.customer') }}</h2>
            <strong>{{ $receipt['billing']['name'] }}</strong>
            <address>{{ $receipt['billing']['address'] ?? '' }}</address>
            <p>{{ ReceiptService::countryName($receipt['billing']['country'] ?? '', $receipt['locale']) }}</p>
            @foreach (['registration', 'vat_id'] as $field)
                @if (!empty($receipt['billing'][$field]))
                    <p>{{ __('shop::commerce.documents.document_fields.customer_' . $field) }} : {{ $receipt['billing'][$field] }}</p>
                @endif
            @endforeach
        </section>
    @endif
    <p>{{ __('shop::commerce.documents.order_reference') }} :
        {{ $receipt['order_reference'] }}<br>{{ __('shop::commerce.documents.account_reference') }} :
        #{{ $receipt['account_reference'] }}<br>{{ __('shop::commerce.documents.issued_at') }} :
        {{ $dates->format($receipt['issued_at']) }}<br>{{ __('shop::commerce.documents.paid_at') }} : {{ $dates->format($receipt['paid_at']) }}</p>
    <p>{{ __('shop::commerce.documents.service_date') }} : {{ $dates->format($receipt['paid_at'], 'd F Y') }}</p>
    @if (isset($receipt['parent_number']))
        <p>{{ __('shop::commerce.documents.original') }} : {{ $receipt['parent_number'] }}</p>
        <p>{{ __('shop::commerce.documents.refund_reference') }} : {{ $receipt['refund_reference'] }}</p>
        @if (!empty($receipt['refund_reason']))
            <p>{{ __('shop::commerce.documents.refund_reason') }} : {{ $receipt['refund_reason'] }}</p>
        @endif
    @endif
    @if (!empty($receipt['review']))
        <p class="muted">{{ __('shop::commerce.documents.review_notice') }}</p>
    @endif
    @if ($receipt['legacy'])
        <p class="muted">{{ __('shop::commerce.documents.historical_notice') }}</p>
    @endif
    @if ($receipt['kind'] === 'payment')
        <table>
            <thead>
                <tr>
                    <th>{{ __('shop::commerce.documents.product') }}</th>
                    <th>{{ __('shop::commerce.documents.quantity') }}</th>
                    <th>{{ __('shop::commerce.documents.unit_net') }}</th>
                    <th>{{ __('shop::messages.site.orders.discount') }}</th>
                    <th>{{ __('shop::commerce.documents.line_net') }}</th>
                    <th>{{ __('shop::commerce.documents.line_tax') }}</th>
                    <th>{{ __('shop::commerce.documents.line_gross') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($receipt['items'] as $item)
                    <tr>
                        <td>{{ $item['name'] }}</td>
                        <td>{{ $item['quantity'] }}</td>
                        <td>{{ isset($item['unit_net_amount']) ? $item['unit_net_amount'] . ' ' . $receipt['currency'] : ReceiptService::money($item['unit_net_minor'] ?? $item['unit_minor'], $receipt['currency']) }}
                        </td>
                        <td>{{ ReceiptService::money(($item['promotion_minor'] ?? 0) + ($item['upgrade_credit_minor'] ?? 0), $receipt['currency']) }}
                        </td>
                        <td>{{ ReceiptService::money($item['net_minor'] ?? $item['unit_minor'] * $item['quantity'], $receipt['currency']) }}
                        </td>
                        <td>{{ ReceiptService::money($item['tax_minor'] ?? 0, $receipt['currency']) }}
                        </td>
                        <td>{{ ReceiptService::money($item['gross_minor'] ?? $item['unit_minor'] * $item['quantity'], $receipt['currency']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="muted">{{ __('shop::commerce.documents.rounding') }}</p>
    @endif
    <dl>
        <dt>{{ __('shop::commerce.documents.net') }}</dt>
        <dd>{{ ReceiptService::money($receipt['net_minor'], $receipt['currency']) }}
        </dd>
        <dt>{{ $receipt['tax_label'] }}
            ({{ intdiv($receipt['rate_bps'], 100) }}.{{ str_pad((string) ($receipt['rate_bps'] % 100), 2, '0', STR_PAD_LEFT) }}
            %)</dt>
        <dd>{{ ReceiptService::money($receipt['tax_minor'], $receipt['currency']) }}
        </dd>
        <dt><strong>{{ __('shop::commerce.documents.gross') }}</strong></dt>
        <dd><strong>{{ ReceiptService::money($receipt['gross_minor'], $receipt['currency']) }}</strong>
        </dd>
        @if ($receipt['kind'] === 'payment')
            @if (!empty($receipt['upgrade_credit_minor']))
                <dt>{{ __('shop::commerce.upgrades.credit') }}</dt>
                <dd>{{ ReceiptService::money($receipt['upgrade_credit_minor'], $receipt['currency']) }}
                </dd>
            @endif
            <dt>
                {{ __('shop::messages.site.orders.discount') }}</dt>
            <dd>{{ ReceiptService::money($receipt['discount_minor'], $receipt['currency']) }}
            </dd>
            <dt>{{ __('shop::messages.site.cart.giftcard') }}</dt>
            <dd>{{ ReceiptService::money($receipt['giftcard_minor'], $receipt['currency']) }}
            </dd>
            @if (!empty($receipt['wallet_minor']))
                <dt>{{ __('shop::commerce.wallet.payment_name') }}</dt>
                <dd>{{ ReceiptService::money($receipt['wallet_minor'], $receipt['currency']) }}</dd>
                <dt>{{ __('shop::commerce.documents.provider_amount') }}</dt>
                <dd>{{ ReceiptService::money($receipt['provider_minor'] ?? $receipt['paid_minor'], $receipt['currency']) }}</dd>
            @endif
            <dt>{{ __('shop::commerce.documents.payment_amount') }}</dt>
            <dd>{{ ReceiptService::money($receipt['paid_minor'], $receipt['currency']) }}
            </dd>
        @else
            <dt>{{ __('shop::commerce.documents.refund_provider_amount') }}</dt>
            <dd>{{ ReceiptService::money($receipt['external_minor'] ?? 0, $receipt['currency']) }}</dd>
            <dt>{{ __('shop::commerce.wallet.payment_name') }}</dt>
            <dd>{{ ReceiptService::money($receipt['wallet_minor'] ?? 0, $receipt['currency']) }}</dd>
            <dt>{{ __('shop::messages.site.cart.giftcard') }}</dt>
            <dd>{{ ReceiptService::money($receipt['giftcard_minor'] ?? 0, $receipt['currency']) }}</dd>
        @endif
    </dl>
    @foreach (['tax_notice', 'payment_terms', 'legal_notice'] as $field)
        @if (!empty($receipt[$field]))
            <p style="white-space: pre-line">{{ $receipt[$field] }}</p>
        @endif
    @endforeach
    @if (!in_array($receipt['document_type'] ?? '', ['invoice', 'credit_note'], true))
        <p class="muted">{{ __('shop::commerce.documents.document_note') }}</p>
    @endif
</body>
</html>
