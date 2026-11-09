@extends('emails.layouts.layout')

@section('subject', __('shop::commerce.documents.mail.subject', ['document' => __('shop::commerce.documents.mail.types.' . $documentType), 'number' => $number]))
@section('preview', __('shop::commerce.documents.mail.intro'))

@section('content')
    <h1 style="margin:0 0 24px;font-size:24px;line-height:1.3;word-break:break-word">{{ __('shop::commerce.documents.mail.types.' . $documentType) }} {{ $number }}</h1>
    <p style="margin:0 0 16px">{{ __('shop::commerce.documents.mail.intro') }}</p>
    <p style="margin:0 0 24px">{{ __('shop::commerce.documents.mail.attachment') }}</p>
    <p style="margin:0">{{ __('shop::commerce.documents.mail.private') }}</p>
@endsection
