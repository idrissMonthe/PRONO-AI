<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protège les routes API mutantes (ingestion, prédiction, Gemini, cotes).
 *
 * L'application est mono-utilisateur : un jeton partagé, défini dans
 * API_TOKEN, suffit à empêcher un poste du réseau (ou un site web visité,
 * puisque l'API est exemptée de CSRF) de déclencher des écritures ou de
 * consommer du crédit fournisseur.
 */
class EnsureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.api.token');

        // Sans jeton configuré, on refuse plutôt que d'ouvrir l'API.
        if ($expected === '') {
            return new JsonResponse([
                'message' => 'API désactivée : API_TOKEN n’est pas configuré.',
            ], 503);
        }

        $provided = $request->header('X-API-Token');

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return new JsonResponse(['message' => 'Non autorisé.'], 401);
        }

        return $next($request);
    }
}
