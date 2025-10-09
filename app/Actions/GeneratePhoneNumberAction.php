<?php

namespace App\Actions;

use Propaganistas\LaravelPhone\PhoneNumber;

class GeneratePhoneNumberAction
{
    public function __invoke(?string $country = null): PhoneNumber
    {
        $countries = [
            'MX' => function () {
                $code = fake()->randomElement(['55', '33', '81', '442', '662', '229', '449', '614']);
                $missingDigits = 10 - strlen($code);
                return $code . fake()->numerify(str_repeat('#', $missingDigits));
            },
            'US' => function () {
                $areaCode = fake()->randomElement(['201', '202', '203', '205', '206', '207', '208', '212', '213', '214', '215', '216', '305', '310']);
                $centralOfficeCode = fake()->numberBetween(200, 999);
                $lineNumber = fake()->numerify(str_repeat('#', 4));
                return $areaCode . $centralOfficeCode . $lineNumber;
            },
        ];

        $selectedCountry = $country && isset($countries[$country]) ? $country : array_rand($countries);

        $randomNumber = is_callable($countries[$selectedCountry])
            ? $countries[$selectedCountry]()
            : fake()->numerify($countries[$selectedCountry]);

        return new PhoneNumber($randomNumber, $selectedCountry);
    }
}
