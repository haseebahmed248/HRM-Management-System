@extends('errors.layout')

@section('code', '419')
@section('title', 'Session Expired')
@section('message', 'Your session has expired due to inactivity. Please refresh the page and try again, or log in again to continue.')

@section('icon')
<svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
</svg>
@endsection
