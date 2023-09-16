<li class="dropdown {{ \App\Misc\Helper::menuSelectedHtml('ameise') }}">
    @if(!file_exists(storage_path('user_'.auth()->user()->id.'_ant.txt')))
    <a href="{{ $url }}">
        {{ __('Connect to Ameise') }}
    </a>
    @else
    <a href="{{route('disconnect.ameise')}}">
        {{ __('Disconnect Ameise') }}
    </a>
    @endif
</li>