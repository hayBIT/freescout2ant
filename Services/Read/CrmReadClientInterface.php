<?php

namespace Modules\AmeiseModule\Services\Read;

/**
 * Read strategy for customer/contract lookups. The return shapes mirror the
 * legacy MitarbeiterWebservice responses so the controller and frontend stay
 * unchanged regardless of the selected API.
 *
 * Note: searching customers by e-mail is intentionally NOT part of this
 * interface. The Stocks API cannot search by e-mail, so that lookup always
 * stays on the MitarbeiterWebservice (CrmApiClient) as a hybrid fallback.
 */
interface CrmReadClientInterface
{
    /**
     * Search customers by customer number or name.
     */
    public function fetchUserByIdOrName($data);

    /**
     * Fetch customer detail for the given endpoint (e.g. 'kontaktdaten').
     */
    public function fetchUserDetail($id, $endPoints);

    /**
     * Fetch contracts for a customer.
     */
    public function getContracts($customerId);

    /**
     * Fetch reference data ('sparten', 'vertragsstatus').
     */
    public function getContactEndPoints($endPoints);
}
