@extends('layouts.app')
@section('title', 'Create salary period')
@section('content')
<div class="card"><div class="card-body">
    <p class="text-muted">Attendance is prorated against the configured working days. Present days and paid leave are payable; unpaid leave reduces the base salary.</p>
    <form method="POST" action="{{ route('payroll.periods.store') }}">
        @csrf
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Period name</label><input name="name" class="form-control" value="{{ old('name') }}" maxlength="20" placeholder="2026-10" required></div>
            <div class="col-md-4"><label class="form-label">Start date</label><input type="date" name="start_date" class="form-control" value="{{ old('start_date') }}" required></div>
            <div class="col-md-4"><label class="form-label">End date</label><input type="date" name="end_date" class="form-control" value="{{ old('end_date') }}" required></div>
            <div class="col-md-4"><label class="form-label">Working days</label><input type="number" name="working_days" class="form-control" value="{{ old('working_days') }}" min="1" max="366" required></div>
        </div>
        <div class="mt-3"><a href="{{ route('payroll.index') }}" class="btn btn-light">Cancel</a><button class="btn btn-primary">Create period</button></div>
    </form>
</div></div>
@endsection
