<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Flc\Dictionary\Application\Query\BuildMeaningsAiPrompt;
use Flc\Dictionary\Application\Query\LookupWord;
use Flc\Dictionary\Application\Query\ResolveLookupWord;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DictionaryController extends Controller
{
    public function __construct(private readonly QueryBus $queries) {}

    public function resolve(string $word): JsonResponse
    {
        $result = $this->queries->ask(new ResolveLookupWord($word));

        if (! $result) {
            return response()->json(['message' => 'Word not found or an error occurred.'], 404);
        }

        return response()->json($result);
    }

    public function show(string $word): JsonResponse
    {
        $result = $this->queries->ask(new LookupWord($word));

        if (! $result) {
            return response()->json(['message' => 'Word not found or an error occurred.'], 404);
        }

        return response()->json($result);
    }

    public function meaningsPrompt(Request $request): JsonResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:120'],
            'meanings' => ['nullable', 'array'],
        ]);

        $result = $this->queries->ask(new BuildMeaningsAiPrompt(
            userId: (int) $request->user()->id,
            word: (string) $data['word'],
            meanings: isset($data['meanings']) && is_array($data['meanings']) ? $data['meanings'] : null,
        ));

        return response()->json(['data' => $result]);
    }
}
