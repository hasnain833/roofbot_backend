<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;

class AISummaryController extends Controller
{
    public function summarize(Request $request)
    {
        $request->validate([
            'lead_id' => 'required|integer|exists:leads,id',
            'message' => 'required|string',
        ]);

        $apiKey = env('OPENAI_API_KEY'); 
        $lead = Lead::find($request->lead_id);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a CRM assistant that summarizes sales leads and conversations concisely.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $request->message
                    ],
                ],
                'max_tokens' => 100,
            ]);

            $summary = $response->json('choices.0.message.content') ?? 'No summary generated.';

            $lead->update(['ai_summary' => $summary]);

            return response()->json([
                'summary' => $summary,
                'message' => 'Summary generated successfully!',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'AI Summary generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
