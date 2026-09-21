<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Flc\Dictionary\Application\Query\LookupWord;
use Flc\Dictionary\Application\Query\ResolveLookupWord;
use Flc\Shared\Application\QueryBus;
use Illuminate\Http\JsonResponse;

class DictionaryController extends Controller
{
    public function __construct(private readonly QueryBus $queries) {}

    public function resolve(string $word): JsonResponse
    {
        $result = $this->queries->ask(new ResolveLookupWord($word));

        if (! $result) {
            return response()->json(['message' => 'Không tìm thấy từ.'], 404);
        }

        return response()->json($result);
    }

    public function show(string $word): JsonResponse
    {
        $result = $this->queries->ask(new LookupWord($word));

        if (! $result) {
            return response()->json(['message' => 'Không tìm thấy từ.'], 404);
        }

        return response()->json($result);
    }
}
