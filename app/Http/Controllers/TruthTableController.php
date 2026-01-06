<?php

namespace App\Http\Controllers;

use App\Services\TruthTableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TruthTableController extends Controller
{
    public function __construct(
        protected TruthTableService $truthTableService
    ) {
    }

    /**
     * Display the truth table generator page.
     */
    public function index(): Response
    {
        return Inertia::render('tools/TruthTableGeneratorPage');
    }

    /**
     * Generate a truth table for the given formula.
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'formula' => ['required', 'string', 'max:500'],
        ]);

        try {
            $truthTable = $this->truthTableService->generate($request->input('formula'));

            return response()->json([
                'success' => true,
                'data' => $truthTable,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'An error occurred while processing the formula.',
            ], 500);
        }
    }
}
