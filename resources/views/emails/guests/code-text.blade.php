@extends('emails.layouts.text')

@section('content')
{!! __('shop::messages.guests.email_subject') !!}

{!! __('shop::messages.guests.email_help') !!}

{!! $code !!}

{!! __('shop::messages.guests.email_private') !!}
@endsection
