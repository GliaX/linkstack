@extends('layouts.sidebar')

@section('content')
<div class="container-fluid content-inner mt-n5 py-0">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title mb-0">Team — {{ $owner->name }}'s page</h4>
                    <a class="btn btn-sm btn-outline-primary" href="{{ url('/@' . $owner->littlelink_name) }}" target="_blank">View page</a>
                </div>
                <div class="card-body">
                    @if(session('team_status'))
                        <div class="alert alert-success">{{ session('team_status') }}</div>
                    @endif
                    @if(session('team_error'))
                        <div class="alert alert-danger">{{ session('team_error') }}</div>
                    @endif

                    @if($actingAs)
                        <div class="alert alert-warning d-flex justify-content-between align-items-center">
                            <span>You are editing <strong>{{ $actingAs->name }}</strong>'s page as a team member.</span>
                            <form action="{{ url('/team/return') }}" method="POST" class="m-0">@csrf
                                <button class="btn btn-sm btn-primary">Return to my account</button>
                            </form>
                        </div>
                    @endif

                    <p class="text-muted">Editors can change this page's links, theme and content. They cannot change your account details, password or email.</p>

                    <h6 class="mb-3">Editors of this page</h6>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr><th>Name</th><th>Email</th><th>Page</th><th></th></tr>
                            </thead>
                            <tbody>
                                @forelse($members as $m)
                                    <tr>
                                        <td>{{ $m->name }}</td>
                                        <td>{{ $m->email }}</td>
                                        <td><a href="{{ url('/@' . $m->littlelink_name) }}" target="_blank">/@{{ $m->littlelink_name }}</a></td>
                                        <td class="text-end">
                                            @unless($actingAs)
                                                <form action="{{ url('/studio/team/remove') }}" method="POST" class="m-0">@csrf
                                                    <input type="hidden" name="member_id" value="{{ $m->id }}">
                                                    <button class="btn btn-sm btn-outline-danger">Remove</button>
                                                </form>
                                            @endunless
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">No editors yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @unless($actingAs)
                        <form action="{{ url('/studio/team/add') }}" method="POST" class="row g-2 align-items-end mt-2">
                            @csrf
                            <div class="col-sm-6">
                                <label class="form-label">Add an editor by email</label>
                                <input class="form-control" type="email" name="email" required placeholder="person at glia.org">
                            </div>
                            <div class="col-sm-3">
                                <button class="btn btn-primary">Add editor</button>
                            </div>
                        </form>
                    @endunless

                    <hr class="my-4">

                    <h6 class="mb-3">Pages you can edit</h6>
                    @if($editable->isEmpty())
                        <p class="text-muted mb-0">Nobody has added you as an editor yet.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th>Owner</th><th>Page</th><th></th></tr></thead>
                                <tbody>
                                    @foreach($editable as $p)
                                        <tr>
                                            <td>{{ $p->name }}</td>
                                            <td><a href="{{ url('/@' . $p->littlelink_name) }}" target="_blank">/@{{ $p->littlelink_name }}</a></td>
                                            <td class="text-end">
                                                <form action="{{ url('/team/act-as/' . $p->id) }}" method="POST" class="m-0">@csrf
                                                    <button class="btn btn-sm btn-primary">Edit this page</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            @if($owner->role === 'admin' && $allPages->isNotEmpty())
            <div class="card mt-3">
                <div class="card-header"><h5 class="card-title mb-0">All pages (admin)</h5></div>
                <div class="card-body">
                    <p class="text-muted">Open any page to manage its editors, then return to your own account.</p>
                    <div class="table-responsive">
                        <table class="table">
                            <thead><tr><th>Owner</th><th>Page</th><th></th></tr></thead>
                            <tbody>
                                @foreach($allPages as $p)
                                    <tr>
                                        <td>{{ $p->name }}</td>
                                        <td><a href="{{ url('/@' . $p->littlelink_name) }}" target="_blank">/@{{ $p->littlelink_name }}</a></td>
                                        <td class="text-end">
                                            <form action="{{ url('/team/act-as/' . $p->id) }}" method="POST" class="m-0">@csrf
                                                <button class="btn btn-sm btn-outline-primary">Manage this page</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
