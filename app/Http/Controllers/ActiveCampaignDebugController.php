<?php

namespace App\Http\Controllers;

use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Http\Request;

class ActiveCampaignDebugController extends Controller
{
    /**
     * Obtener todos los fields (campos personalizados)
     */
    public function fields(ActiveCampaignService $service)
    {
        $fields = $service->getFields();

        return response()->json([
            'total' => count($fields),
            'fields' => $fields
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Obtener todos los tags
     */
    public function tags(ActiveCampaignService $service)
    {
        $tags = $service->getTags();

        // Verificar que $tags sea un array
        if (!is_array($tags)) {
            return response()->json([
                'error' => 'La respuesta de ActiveCampaign no es un array válido',
                'tags' => $tags
            ], 500);
        }

        // Formatear todos los tags de manera segura
        $formattedTags = [];
        $invalidTags = [];

        foreach ($tags as $index => $tag) {
            // Verificar que $tag sea un array
            if (is_array($tag)) {
                // Si tiene nombre, lo formateamos normalmente
                if (isset($tag['name'])) {
                    $formattedTags[] = [
                        'id' => $tag['id'] ?? null,
                        'name' => $tag['name'],
                        'tagType' => $tag['tagType'] ?? 'contact',
                        'description' => $tag['description'] ?? '',
                        'created_at' => $tag['cdate'] ?? null,
                        'updated_at' => $tag['udate'] ?? null,
                        'raw_data' => $tag // Por si quieres ver todos los campos disponibles
                    ];
                } else {
                    // Si no tiene nombre, guardamos la estructura para debug
                    $invalidTags[] = [
                        'index' => $index,
                        'structure' => array_keys($tag),
                        'raw' => $tag
                    ];
                }
            } else {
                $invalidTags[] = [
                    'index' => $index,
                    'type' => gettype($tag),
                    'value' => $tag
                ];
            }
        }

        return response()->json([
            'success' => true,
            'total_tags_raw' => count($tags),
            'total_tags_formatted' => count($formattedTags),
            'total_invalid_tags' => count($invalidTags),
            'tags' => $formattedTags,
            'invalid_tags' => $invalidTags, // Esto te ayudará a debuggear
            'debug_info' => [
                'first_tag_sample' => isset($tags[0]) ? $tags[0] : null,
                'tags_type' => gettype($tags)
            ]
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Buscar tags por término
     */
    public function searchTags(Request $request, ActiveCampaignService $service)
    {
        $search = $request->get('q', '');

        if (empty($search)) {
            return response()->json([
                'error' => 'Se requiere un término de búsqueda'
            ], 400);
        }

        $tags = $service->searchTags($search);

        return response()->json([
            'search_term' => $search,
            'total' => count($tags),
            'tags' => $tags
        ], 200, [], JSON_PRETTY_PRINT);
    }

    /**
     * Crear un nuevo tag
     */
    public function createTag(Request $request, ActiveCampaignService $service)
    {
        $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'tagType' => 'sometimes|in:contact,template,deal'
        ]);

        $tag = $service->createTag(
            $request->name,
            $request->get('tagType', 'contact'),
            $request->get('description', '')
        );

        if (!$tag) {
            return response()->json([
                'error' => 'No se pudo crear el tag'
            ], 500);
        }

        return response()->json([
            'message' => 'Tag creado exitosamente',
            'tag' => $tag
        ], 201, [], JSON_PRETTY_PRINT);
    }

    /**
     * Método helper para crear todos los tags que necesitas
     */
    public function createMissingTags(ActiveCampaignService $service)
    {
        $requiredTags = [
            'Nuevos registros',
            'Completo compra',
            'Agrego carrito',
            'Abandono carrito',
            'Termino la membresía',
            'Activa membresía',
            'Laboratorio Agrega paciente (análisis)',
            'Resultados disponible',
            'Factura Disponible'
        ];

        $results = [];

        foreach ($requiredTags as $tagName) {
            $tagId = $service->getOrCreateTag($tagName);

            $results[$tagName] = [
                'id' => $tagId,
                'status' => $tagId ? 'exists_or_created' : 'failed'
            ];
        }

        return response()->json([
            'message' => 'Verificación/creación de tags completada',
            'results' => $results
        ], 200, [], JSON_PRETTY_PRINT);
    }
}