@extends('emails.layouts.text')

@section('content')
{!! __('shop::commerce.documents.mail.subject', ['document' => __('shop::commerce.documents.mail.types.' . $documentType), 'number' => $number]) !!}

{!! __('shop::commerce.documents.mail.intro') !!}

{!! __('shop::commerce.documents.mail.attachment') !!}

{!! __('shop::commerce.documents.mail.private') !!}
@endsection
