@extends('emails.layouts.text')

@section('content')
{!! __('shop::commerce.giftcards.mail.subject') !!}

{!! __('shop::commerce.giftcards.mail.intro', ['last_four' => $lastFour]) !!}

@if ($event === 'claim')
{!! __('shop::commerce.giftcards.mail.claim_help') !!}

{!! __('shop::commerce.giftcards.claim') !!}
{!! $value !!}
@else
{!! __('shop::commerce.giftcards.mail.code_help') !!}

{!! __('shop::commerce.giftcards.code') !!} : {!! $value !!}
@endif

{!! __('shop::commerce.giftcards.mail.private') !!}
@endsection
