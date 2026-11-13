<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderEntityMap extends Model
{
    use HasFactory;

    protected $table = 'provider_entity_maps';

    protected $fillable = [
        'entity_type',
        'internal_id',
        'provider',
        'external_id',
        'metadata',
    ];

    protected $casts = [
        'internal_id' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Enregistre (ou met à jour) la correspondance entre un identifiant
     * fournisseur et l'entité interne. Aucun identifiant n'est inventé : un
     * appel sans identifiant externe est ignoré.
     */
    public static function record(
        string $entityType,
        string $provider,
        ?string $externalId,
        int $internalId,
        array $metadata = []
    ): ?self {
        if ($externalId === null || trim($externalId) === '' || $internalId <= 0) {
            return null;
        }

        return static::updateOrCreate(
            [
                'entity_type' => $entityType,
                'provider' => $provider,
                'external_id' => $externalId,
            ],
            [
                'internal_id' => $internalId,
                'metadata' => $metadata ?: null,
            ]
        );
    }
}
