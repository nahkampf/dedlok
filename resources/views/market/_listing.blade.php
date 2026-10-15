@use('App\Support\Text')
{{-- The fragment the listing modal fetches: the offer's card, or a line saying it is gone. --}}
@if ($offer)
    @include('market._offer', ['offer' => $offer])
@else
    <p class="muted" style="margin:0">{{ Text::get('market.offers.gone') }}</p>
@endif
