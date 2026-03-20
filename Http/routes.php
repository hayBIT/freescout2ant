<?php

Route::group(['middleware' => ['web', 'auth'], 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\AmeiseModule\Http\Controllers'], function()
{
    Route::get('/ameise/{id}/get-contracts', ['uses' => 'AmeiseController@getContracts'])->name('ameise.get.contracts');
    Route::post('/ameise/ajax', ['uses' => 'AmeiseController@ajax', 'laroute' => true])->name('ameise.ajax');
    Route::get('/ameise/refresh-token', ['uses' => 'AmeiseController@refreshToken'])->name('ameise.refresh');
    Route::get('/crm/auth', ['uses' => 'AmeiseModuleController@auth', 'laroute' => true])->name('crm.auth');
});
