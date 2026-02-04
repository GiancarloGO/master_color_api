@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<img src="https://mastercolorfiles.s3.us-east-2.amazonaws.com/emails/mc-logo.png" alt="Master Color" style="height: 50px; width: 50px; max-width: 50px; display: block;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
