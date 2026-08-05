<?php

namespace App\Services\ClinicalMatching;

class SynonymCatalog
{
    /**
     * @return array<string, string>
     */
    public function abbreviations(): array
    {
        return [
            'bh' => 'biometria hematica',
            'qs' => 'quimica sanguinea',
            'qs6' => 'quimica sanguinea 6 elementos',
            'qs12' => 'quimica sanguinea 12 elementos',
            'ego' => 'examen general de orina',
            'pth' => 'hormona paratiroidea',
            'tsh' => 'hormona estimulante de tiroides',
            't3' => 'triiodotironina',
            't4' => 'tiroxina',
            'hba1c' => 'hemoglobina glucosilada',
            'vdrl' => 'prueba de sifilis',
            'pcr' => 'proteina c reactiva',
            'vhs' => 'velocidad de sedimentacion globular',
            'tp' => 'tiempo de protrombina',
            'tpt' => 'tiempo parcial de tromboplastina',
            'inr' => 'razon internacional normalizada',
            'psa' => 'antigeno prostatico especifico',
            'igg' => 'inmunoglobulina g',
            'igm' => 'inmunoglobulina m',
            'iga' => 'inmunoglobulina a',
        ];
    }

    /**
     * @return array<int, array{canonical: string, aliases: list<string>}>
     */
    public function groups(): array
    {
        return [
            [
                'canonical' => 'biometria hematica',
                'aliases' => ['bh', 'biometria', 'citohemograma', 'hemograma', 'biometria hematica completa'],
            ],
            [
                'canonical' => 'quimica sanguinea 6 elementos',
                'aliases' => ['qs6', 'qs 6', 'quimica 6', 'quimica sanguinea 6'],
            ],
            [
                'canonical' => 'quimica sanguinea',
                'aliases' => ['qs', 'quimica', 'quimica sanguinea'],
            ],
            [
                'canonical' => 'perfil hormonal masculino',
                'aliases' => ['perfil hormonal hombre', 'perfil hormonal masculino', 'hormonas masculinas'],
            ],
            [
                'canonical' => 'perfil hormonal femenino',
                'aliases' => ['perfil hormonal mujer', 'perfil hormonal femenino', 'hormonas femeninas'],
            ],
            [
                'canonical' => 'perfil hormonal',
                'aliases' => [
                    'perfil hormonal',
                    'perfil hormonal masculino',
                    'perfil hormonal femenino',
                    'perfil hormonal infantil',
                    'perfil hormonal hombre',
                    'perfil hormonal mujer',
                ],
            ],
            [
                'canonical' => 'examen general de orina',
                'aliases' => ['ego', 'orina', 'uroanalisis'],
            ],
            [
                'canonical' => 'hemoglobina glucosilada',
                'aliases' => ['hba1c', 'hb a1c', 'glucosilada'],
            ],
            [
                'canonical' => 'perfil tiroideo',
                'aliases' => ['tiroides', 'perfil de tiroides', 'tsh t3 t4'],
            ],
            [
                'canonical' => 'perfil lipidico',
                'aliases' => ['lipidos', 'colesterol', 'perfil de lipidos'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function expandQuery(string $normalized): array
    {
        $variants = [$normalized];

        foreach ($this->groups() as $group) {
            $canonical = $group['canonical'];
            $aliases = array_merge([$canonical], $group['aliases']);

            foreach ($aliases as $alias) {
                if ($normalized === $alias || str_contains($normalized, $alias) || str_contains($alias, $normalized)) {
                    $variants[] = $canonical;
                    foreach ($group['aliases'] as $extra) {
                        $variants[] = $extra;
                    }
                    break;
                }
            }
        }

        return array_values(array_unique($variants));
    }
}
