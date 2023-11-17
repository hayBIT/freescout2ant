<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\AmeiseModule\Http\Controllers'], function()
{
    Route::get('/', 'AmeiseModuleController@index');
    Route::get('/ameise/{id}/get-contracts', ['uses' => 'AmeiseController@getContracts'])->name('ameise.get.contracts');
    Route::post('/ameise/ajax', ['uses' => 'AmeiseController@ajax', 'laroute' => true])->name('ameise.ajax');
    Route::get('/crm/auth', ['uses' => 'AmeiseModuleController@auth', 'laroute' => true])->name('crm.auth');

});
