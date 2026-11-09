@extends('emails.layouts.layout')

@section('subject', __('shop::messages.guests.email_subject'))
@section('preview', __('shop::messages.guests.email_help'))

@section('content')
    <h1 style="margin:0 0 24px;font-size:24px;line-height:1.3">{{ __('shop::messages.guests.email_subject') }}</h1>
    <p style="margin:0 0 20px">{{ __('shop::messages.guests.email_help') }}</p>
    <p class="mail-code" style="margin:0 0 24px;letter-spacing:4px;text-align:center">{{ $code }}</p>
    <p style="margin:0">{{ __('shop::messages.guests.email_private') }}</p>
@endsection
