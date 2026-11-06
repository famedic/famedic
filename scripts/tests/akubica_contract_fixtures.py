"""Hand-written synthetic serializer fixtures. Never load incident reports."""
from copy import deepcopy


def fixtures():
    lab_item = {'id': 7, 'laboratory_test_id': 11, 'name': 'fixture-item', 'price_cents': 13700,
                'currency': 'MXN', 'quantity': 1, 'requires_appointment': False}
    lab_cart = {'brand': 'olab', 'items': [lab_item], 'subtotal_cents': 22000,
                'discount_cents': 8300, 'total_cents': 13700}
    lab_core = {'currency': 'MXN', 'subtotal_cents': 22000, 'discount_cents': 8300,
                'coupon_discount_cents': 0, 'total_cents': 13700}
    lab_totals = {'brand': 'olab', 'items_count': 1, **lab_core, 'coupon': None}
    med_item = {'id': 13, 'item_type': 'membership', 'name': 'fixture-plan',
                'membership_plan_code': 'basic_annual', 'laboratory_test_id': None, 'brand': None,
                'quantity': 1, 'unit_price_cents': 42000, 'subtotal_cents': 42000,
                'requires_appointment': False}
    med_core = {'subtotal_cents': 42000, 'discount_cents': 0, 'credit_applied_cents': 0,
                'total_cents': 42000, 'currency': 'MXN', 'item_count': 1}
    med_cart = {'cart_id': 17, 'service_line': 'medical_services', 'items': [med_item],
                'totals': med_core, 'applied_benefits': [], 'requires_appointment': False}
    med_totals = {**med_core, 'service_line': 'medical_services', 'applied_benefits': []}
    requirements = {'has_items': True, 'requires_contact': False, 'requires_address': False,
                    'requires_appointment': False, 'requires_invoice_data': False}
    med_checkout = {'service_line': 'medical_services', 'cart': med_cart, 'requirements': requirements,
                    'warnings': [], 'assistant_can_pay': False, 'can_continue_on_platform': True,
                    'can_continue_to_payment_platform': False}
    lab_checkout = {'brand': 'olab', 'cart': {'items': [lab_item], 'totals': {**lab_core, 'coupon': None}},
                    'coupon': None, 'contacts': [], 'addresses': [], 'payment_methods': [],
                    'requirements': {**requirements, 'requires_contact': True, 'requires_address': True},
                    'warnings': [], 'can_continue_to_payment_platform': True}
    token = {'token': '{{synthetic_bearer}}', 'token_type': 'Bearer', 'expires_in': 10800,
             'expires_at': '2026-11-05T18:00:00Z', 'user': {'id': 1}}
    contextual = {**token, 'otp_verified': True, 'assurance_level': 'otp_verified',
                  'personalization': {'allowed': False}}
    coupon = {'code': 'FIXTURE', 'type': 'balance', 'value': 5000, 'discount_cents': 5000,
              'description': 'fixture-benefit'}
    coupon_totals = {**lab_core, 'items_count': 1}
    wrap = lambda data: {'success': True, 'data': deepcopy(data)}
    cases = [
        ('/auth/login/verify-code', 'post', '200', 'LoginVerifiedResponse', wrap(token)),
        ('/auth/login/verify-code', 'post', '200', 'LoginVerifiedResponse', wrap(contextual)),
        ('/cart/items', 'post', '201', 'LaboratoryCartItemAddedResponse', wrap({'item': lab_item, 'cart': lab_cart})),
        ('/cart/items', 'post', '201', 'MedicalServiceCartResponse', wrap(med_cart)),
        ('/cart', 'get', '200', 'LaboratoryCartResponse', wrap(lab_cart)),
        ('/cart', 'get', '200', 'MedicalServiceCartResponse', wrap(med_cart)),
        ('/cart/totals', 'get', '200', 'LaboratoryCartTotalsResponse', wrap(lab_totals)),
        ('/cart/totals', 'get', '200', 'MedicalServiceCartTotalsResponse', wrap(med_totals)),
        ('/cart/items/{cart_item_id}', 'delete', '200', 'LaboratoryCartItemRemovedResponse', wrap({'removed_item_id': 7, 'cart': lab_cart})),
        ('/cart/items/{cart_item_id}', 'delete', '200', 'MedicalServiceCartResponse', wrap(med_cart)),
        ('/checkout/prepare', 'get', '200', 'LaboratoryCheckoutPrepareResponse', wrap(lab_checkout)),
        ('/checkout/prepare', 'get', '200', 'MedicalServiceCheckoutPrepareResponse', wrap(med_checkout)),
        ('/cart/medical-benefit', 'get', '200', 'MedicalServiceBenefitResponse', wrap({'service_line': 'medical_services', 'cart': med_cart})),
        ('/cart/medical-benefit', 'post', '200', 'MedicalServiceBenefitResponse', wrap({'service_line': 'medical_services', 'cart': med_cart})),
        ('/cart/medical-benefit', 'delete', '200', 'MedicalServiceBenefitRemovedResponse', wrap({'service_line': 'medical_services', 'cart': med_cart, 'removed': True})),
        ('/cart/medical-benefit', 'delete', '200', 'MedicalServiceBenefitRemovedResponse', wrap({'service_line': 'medical_services', 'cart': med_cart, 'removed': False})),
        ('/cart/coupon', 'get', '200', 'LaboratoryCouponResponse', wrap({'brand': 'olab', 'coupon': None})),
        ('/cart/coupon', 'post', '200', 'LaboratoryCouponAppliedResponse', wrap({'brand': 'olab', 'coupon': coupon, 'totals': coupon_totals})),
        ('/cart/coupon', 'delete', '200', 'LaboratoryCouponRemovedResponse', wrap({'brand': 'olab', 'removed': False, 'coupon': None})),
        ('/cart/coupon', 'delete', '200', 'LaboratoryCouponRemovedResponse', wrap({'brand': 'olab', 'removed': True, 'coupon': None, 'totals': coupon_totals})),
    ]
    return cases
