@extends('layouts.app')
@php
    $editing = $party->exists;
@endphp
@section('title', ($editing ? 'Edit ' : 'New ') . strtolower($cfg['labels']['singular']))
@section('content')
@php
    $p = $cfg['prefix'];
@endphp
<form method="POST" action="{{ $editing ? route($p . '.update', $party->id) : route($p . '.store') }}">
    @csrf
    @if ($editing) @method('PUT') @endif

    <div class="card"><div class="card-body"><div class="row g-3">
        @foreach ($fields as $f)
            <div class="col-md-{{ $f['cols'] ?? 6 }}">
                @if ($f['type'] === 'checkbox')
                    <div class="form-check mt-4">
                        <input type="hidden" name="{{ $f['name'] }}" value="0">
                        <input class="form-check-input" type="checkbox" name="{{ $f['name'] }}" value="1" id="f_{{ $f['name'] }}" @checked(old($f['name'], $party->{$f['name']}))>
                        <label class="form-check-label" for="f_{{ $f['name'] }}">{{ $f['label'] }}</label>
                    </div>
                @else
                    <label class="form-label" for="f_{{ $f['name'] }}">{{ $f['label'] }}</label>
                    @if ($f['type'] === 'textarea')
                        <textarea id="f_{{ $f['name'] }}" name="{{ $f['name'] }}" rows="2" class="form-control @error($f['name']) is-invalid @enderror">{{ old($f['name'], $party->{$f['name']}) }}</textarea>
                    @else
                        <input id="f_{{ $f['name'] }}" name="{{ $f['name'] }}" type="{{ $f['type'] === 'number' ? 'text' : $f['type'] }}" @if ($f['type'] === 'number') inputmode="decimal" @endif
                               value="{{ old($f['name'], $party->{$f['name']}) }}" class="form-control @error($f['name']) is-invalid @enderror" @required($f['required'] ?? false) @if (! empty($f['help'])) placeholder="{{ $f['help'] }}" @endif>
                    @endif
                    @error($f['name'])<div class="invalid-feedback">{{ $message }}</div>@enderror
                @endif
            </div>
        @endforeach
    </div></div></div>

    @if (! $editing && $cfg['supportsOpening'])
        @can('journal.post')
            <div class="card mt-3"><div class="card-body">
                <h6>Opening balance <small class="text-muted">(optional)</small></h6>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Amount (PKR)</label><input name="opening_amount" class="form-control text-end" inputmode="decimal" value="{{ old('opening_amount') }}"></div>
                    <div class="col-md-5"><label class="form-label">Direction</label>
                        <select name="opening_side" class="form-select">@foreach ($cfg['labels']['sides'] as $v => $l)<option value="{{ $v }}" @selected(old('opening_side') === $v)>{{ $l }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label">As of</label><input type="date" name="opening_date" class="form-control" value="{{ old('opening_date', now()->toDateString()) }}"></div>
                </div>
                <p class="small text-muted mb-0 mt-2">Posted against Opening Balance Equity. Balances are always calculated from the journal.</p>
            </div></div>
        @endcan
    @endif

    <div class="mt-3">
        <a href="{{ route($p . '.index') }}" class="btn btn-light">Cancel</a>
        <button class="btn btn-primary">{{ $editing ? 'Save changes' : 'Create' }}</button>
    </div>
</form>

@if ($editing && $cfg['supportsOpening'])
    @include('parties._opening')
@endif
@endsection
