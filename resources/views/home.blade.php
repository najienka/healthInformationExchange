@extends('layouts.app')

@section('content')
<div class="container">
	<div class="row">
		<div class="col-md-10 col-md-offset-1">
			<div class="panel panel-default">
				<div class="panel-heading">
					<div class="container-fluid panel-container">
						<div class="col-xs-6 col-md-9 text-left">
							<h4 class="panel-title" style="height:35px;display:table-cell !important;vertical-align:middle;">{!! $title !!}</h4>
						</div>
						<div class="col-xs-3 text-right">
							@if (isset($back))
								{!! $back !!}
							@endif
						</div>
					</div>

				</div>
				<div class="panel-body">
					@if (isset($message_action))
						<div class="alert alert-success">
							<strong>{{ $message_action }}</strong>
						</div>
					@endif
					@if (isset($pnosh))
						<div class="alert alert-danger">
							<h4>Register your resources!</h4>
							<p>Install and register your pNOSH, the one true health record.</p>
							<p><a href="{{ $pnosh_url }}">Get Started</a></p>
						</div>
					@endif
					@if (isset($mdnosh))
						<div class="alert alert-danger">
							<h4>Get connected!</h4>
							<p>Get connected to mdNOSH Gateway, a single-sign-on solution for physicians.</p>
							<p>With mdNOSH Gateway, your authorization server can connect to thousands of physicians who may be involved in your care.</p>
							<p><a href="https://noshchartingsystem.com/oidc/uma_register">Get Started</a></p>
						</div>
					@endif
					{!! $content !!}
				</div>
			</div>
		</div>
	</div>
</div>
@endsection

@section('view.scripts')
<script type="text/javascript">
	$(document).ready(function() {
		$("#remove_permissions_button").on('click', function() {
			return confirm('Removing all permissions cannot be undone!');
		});
	});
</script>
@endsection
