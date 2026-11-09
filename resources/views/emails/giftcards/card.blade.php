@extends('emails.layouts.layout')

@section('subject', __('shop::commerce.giftcards.mail.subject'))
@section('preview', __('shop::commerce.giftcards.mail.intro', ['last_four' => $lastFour]))

@section('content')
    <h1 style="margin:0 0 24px;font-size:24px;line-height:1.3">{{ __('shop::commerce.giftcards.mail.subject') }}</h1>
    <p style="margin:0 0 16px">{{ __('shop::commerce.giftcards.mail.intro', ['last_four' => $lastFour]) }}</p>
    @if ($event === 'claim')
        <p style="margin:0 0 24px">{{ __('shop::commerce.giftcards.mail.claim_help') }}</p>
        @include('emails.partials.button', ['action' => __('shop::commerce.giftcards.claim'), 'actionUrl' => $value])
    @else
        <p style="margin:0 0 20px">{{ __('shop::commerce.giftcards.mail.code_help') }}</p>
        <p class="mail-code" style="margin:0 0 24px">{{ __('shop::commerce.giftcards.code') }} : {{ $value }}</p>
    @endif
    <p style="margin:0">{{ __('shop::commerce.giftcards.mail.private') }}</p>
@endsection
