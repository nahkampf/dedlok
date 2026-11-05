@use('App\Support\Ansi')
{{-- A text file's words as the reader dialog shows them: escaped, as typed, in the DOS font; ANSI drawn on its screen grid. --}}
<pre class="ascii{{ Ansi::in($text->body) ? ' ansi' : '' }}" style="margin:0;overflow-x:auto">{!! Ansi::render($text->body, $text->ice) !!}</pre>
