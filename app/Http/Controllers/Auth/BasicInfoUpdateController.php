<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\BasicInfoUpdateRequest;
use Illuminate\Support\Facades\Redirect;

class BasicInfoUpdateController extends Controller
{
    public function __invoke(BasicInfoUpdateRequest $request)
    {
        $request->user()->fill($request->validated());

        $request->user()->save();

        return Redirect::route('user.edit')->flashMessage('Tu información básica ha sido actualizada.');
    }
}
