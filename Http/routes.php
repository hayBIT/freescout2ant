<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\AmeiseModule\Http\Controllers'], function()
{
    Route::get('/', 'AmeiseModuleController@index');
    Route::get('/crm/{id}/get-contracts', ['uses' => 'CrmController@getContracts'])->name('crm.get.contracts');
    Route::post('/crm/ajax', ['uses' => 'CrmController@ajax', 'laroute' => true])->name('crm.ajax');
    Route::post('/crm/auth', ['uses' => 'AmeiseModuleController@auth', 'laroute' => true])->name('crm.auth');

});
