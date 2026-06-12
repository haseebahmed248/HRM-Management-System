@extends('errors.layout')

@section('code', '403')
@section('title', 'Access Forbidden')
@section('message', 'You don\'t have permission to access this resource. If you believe this is an error, please contact your administrator.')

@section('icon')
<svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
</svg>
@endsection
