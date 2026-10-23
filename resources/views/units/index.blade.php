@extends('layouts.app')
@section('title', 'Units of measure')
@section('content')
<div class="row g-3">
    <div class="col-lg-8"><div class="card">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Name</th><th>Short</th><th>Products</th><th></th></tr></thead>
            <tbody>
            @foreach ($units as $row)
                <tr>
                    <td colspan="3">
                        @can('categories.update')
                        <form method="POST" action="{{ route('units.update', $row) }}" class="row g-2 align-items-center">
                            @csrf @method('PUT')
                            <div class="col-5"><input name="name" value="{{ $row->name }}" class="form-control form-control-sm" required></div>
                            <div class="col-4"><input name="short_name" value="{{ $row->short_name }}" class="form-control form-control-sm"></div>
                            <div class="col-1 text-center">{{ $row->products_count }}</div>
                            <div class="col-2"><button class="btn btn-sm btn-outline-primary">Save</button></div>
                        </form>
                        @else
                            {{ $row->name }} · {{ $row->short_name }} · {{ $row->products_count }}
                        @endcan
                    </td>
                    <td class="text-end">
                        @can('categories.delete')
                        <form method="POST" action="{{ route('units.destroy', $row) }}" onsubmit="return confirm('Delete {{ $row->name }}?')">
                            @csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form>
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div></div>
    @can('categories.create')
    <div class="col-lg-4"><div class="card"><div class="card-body">
        <h6>Add unit</h6>
        <form method="POST" action="{{ route('units.store') }}">@csrf
            <div class="mb-2"><input name="name" class="form-control" placeholder="Name" required maxlength="100"></div>
            <div class="mb-2"><input name="short_name" class="form-control" placeholder="Short name (e.g. kg)"></div>
            <button class="btn btn-primary btn-sm">Add</button>
        </form>
        <hr><a href="{{ route('categories.index') }}">Back to categories</a>
    </div></div></div>
    @endcan
</div>
@endsection
