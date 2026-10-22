@extends('layouts.store')
@section('title', $document->title.' | Sài Gòn CMC')
@section('content')
<div class="container-fluid container-wide py-5">
    <a href="{{ route('assistant') }}">← Trợ lý tư vấn</a>
    <h1 class="page-title mt-4">{{ $document->title }}</h1>
    <h2 class="h4">{{ $chunk['title'] }}</h2>
    <p class="muted">Nguồn: {{ $document->filename }} · Dòng gốc: {{ $chunk['line'] }}</p>
    <article class="order-card"><p style="white-space:pre-wrap">{{ $chunk['content'] }}</p></article>
</div>
@endsection
