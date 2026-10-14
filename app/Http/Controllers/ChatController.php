<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Chat\ChatRefused;
use App\Chat\Chats;
use App\Models\Character;
use App\Models\Chat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The chat page. Every action names a chat by id and is judged by `Chats::visible()`: a chat the character
 * cannot see is a 404, the same as one that does not exist.
 *
 * Writes answer JSON `{chat: id}` and the page goes to that chat; the page's timer reads `chat.poll`, the same screen without the page around it.
 */
class ChatController extends Controller
{
    /** The chat page. `?chat=` picks the chat; General when absent. */
    public function show(Request $request, Chats $chats): View
    {
        return $this->view('chat.index', $request, $chats);
    }

    /** The same screen without the page around it, for the open page's timer to swap the tabs and log from. */
    public function poll(Request $request, Chats $chats): View
    {
        return $this->view('chat.panel', $request, $chats);
    }

    private function view(string $name, Request $request, Chats $chats): View
    {
        $me = $this->me($request);
        $chat = $request->filled('chat') ? $chats->visible($me, (int) $request->query('chat')) : $chats->general();
        abort_if($chat === null, 404);

        // Looking at it is reading it — but not the background poll's idea of looking, which would clear a badge nobody saw.
        if ($chat->kind !== Chat::GENERAL) {
            $chats->markRead($me, $chat);
        }

        return view($name, [
            'chat' => $chat,
            'tabs' => $chats->tabs($me),
            'lines' => $chats->lines($chat),
            'active' => $chats->activeCount(),
            'joinable' => $chats->joinable($me),
            'me' => $me,
        ]);
    }

    public function post(Request $request, Chats $chats): JsonResponse
    {
        $me = $this->me($request);
        $chat = $chats->visible($me, (int) $request->input('chat'));
        abort_if($chat === null, 404);

        // The line's own chat: a `/msg` lands in a direct line, and the page switches to it.
        return $this->answer(fn (): int => $chats->post($me, $chat, (string) $request->input('message'))->chat_id);
    }

    public function store(Request $request, Chats $chats): JsonResponse
    {
        $me = $this->me($request);

        return $this->answer(fn (): int => $chats->createRoom($me, (string) $request->input('name'), $request->boolean('public'))->getKey());
    }

    public function join(Request $request, Chats $chats, int $chat): JsonResponse
    {
        $me = $this->me($request);
        $room = Chat::query()->find($chat);
        abort_if($room === null || $room->kind !== Chat::ROOM || ! $room->is_public, 404);

        return $this->answer(function () use ($chats, $me, $room): int {
            $chats->join($me, $room);

            return $room->getKey();
        });
    }

    public function invite(Request $request, Chats $chats, int $chat): JsonResponse
    {
        $me = $this->me($request);
        $room = $chats->visible($me, $chat);
        abort_if($room === null, 404);

        return $this->answer(function () use ($chats, $me, $room, $request): int {
            $chats->invite($me, $room, (string) $request->input('handle'));

            return $room->getKey();
        });
    }

    private function me(Request $request): Character
    {
        return $request->user()->character;
    }

    /** @param  callable(): int  $do */
    private function answer(callable $do): JsonResponse
    {
        try {
            return response()->json(['chat' => $do()]);
        } catch (ChatRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
