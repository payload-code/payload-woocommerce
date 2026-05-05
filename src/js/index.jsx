import React, { useEffect, useRef, useState } from 'react';
import * as ReactDOM from 'react-dom';
import { decodeEntities } from '@wordpress/html-entities';
import {
	PaymentForm,
	PaymentMethodForm,
	Card,
	PayloadInput,
} from 'payload-react';

import '../css/style.scss';
/* global MutationObserver, Node */

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;

const PAYMENT_METHOD_NAME = 'payload';

const settings = getSetting( PAYMENT_METHOD_NAME, {} );
const label =
	decodeEntities( settings.title ) ||
	window.wp.i18n.__( 'Credit/Debit Card', 'payload' );

const PaymentMethodFields = () => {
	const [ nameInvalidMessage, setNameInvalidMessage ] = useState();
	const [ cardInvalidMessage, setCardInvalidMessage ] = useState();

	return (
		<div className="pl-form-container">
			<div className="pl-form-control">
				<label
					className="pl-input-label"
					htmlFor="payload-account-holder"
				>
					Name on card
				</label>
				<PayloadInput
					id="payload-account-holder"
					attr="account_holder"
					placeholder="First and last"
					onInvalid={ ( evt ) => {
						setNameInvalidMessage( evt.message );
					} }
					onValid={ () => setNameInvalidMessage( null ) }
				/>
				<div className="pl-invalid-hint">{ nameInvalidMessage }</div>
			</div>
			<div className="pl-form-control">
				<label className="pl-input-label" htmlFor="payload-card">
					Card details
				</label>
				<Card
					id="payload-card"
					className="payload-card-input"
					onInvalid={ ( evt ) => {
						setCardInvalidMessage( evt.message );
					} }
					onValid={ () => setCardInvalidMessage( null ) }
				/>
				<div className="pl-invalid-hint">{ cardInvalidMessage }</div>
			</div>
		</div>
	);
};

const Content = ( props ) => {
	const { eventRegistration, emitResponse, billing, activePaymentMethod } =
		props;
	const { onPaymentSetup } = eventRegistration;
	const [ clientToken, setClientToken ] = useState();
	const [ fetchError, setFetchError ] = useState();
	const paymentFormRef = useRef( null );
	const hasSubscription = !! props.cartData.extensions?.subscriptions?.length;
	const isGuest = ! wp.data.select( 'wc/store/checkout' )?.getCustomerId();

	useEffect( () => {
		wp.apiFetch( { path: 'wc/v3/payload_client_token' } )
			.then( ( data ) => setClientToken( data.client_token ) )
			.catch( ( err ) => {
				setFetchError( err?.message || 'Unable to load payment form.' );
			} );

		const unsubscribe = onPaymentSetup( async () => {
			if ( activePaymentMethod !== PAYMENT_METHOD_NAME ) {
				return;
			}

			try {
				const result = await paymentFormRef.current.submit();
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							transactionId: result.transaction_id,
						},
					},
				};
			} catch ( e ) {
				let errorMessage;
				if ( e.data?.error_type !== 'InvalidAttributes' ) {
					errorMessage = e.data?.error_description;
				}

				return {
					type: emitResponse.responseTypes.ERROR,
					message: errorMessage ?? 'There was an error',
				};
			}
		} );

		// Unsubscribes when this component is unmounted.
		return () => {
			unsubscribe();
		};
	}, [
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
		onPaymentSetup,
		activePaymentMethod,
	] );

	if ( fetchError ) {
		return <div className="pl-form-error">{ fetchError }</div>;
	}

	if ( ! clientToken ) {
		return;
	}

	return (
		<>
			{ decodeEntities( settings.description || '' ) }
			<PaymentForm
				ref={ paymentFormRef }
				clientToken={ clientToken }
				styles={ { invalid: 'pl-input-invalid' } }
				preventDefaultOnSubmit={ true }
				payment={ {
					amount: billing.cartTotal.value / 100,
					payment_method: {
						keep_active: hasSubscription || ! isGuest,
					},
				} }
			>
				<PaymentMethodFields />
			</PaymentForm>
		</>
	);
};

const AddPaymentMethod = () => {
	const [ clientToken, setClientToken ] = useState();
	const [ paymentMethodId, setPaymentMethodId ] = useState();
	const [ generalErrorMessage, setGeneralErrorMessage ] = useState();
	const addPaymentPaymentFormRef = useRef( null );

	const getForm = () => {
		return (
			document.getElementById( 'order_review' ) ??
			document.getElementById( 'add_payment_method' )
		);
	};

	useEffect( () => {
		wp.apiFetch( {
			path: 'wc/v3/payload_client_token?type=payment_method',
		} )
			.then( ( data ) => setClientToken( data.client_token ) )
			.catch( ( err ) => {
				setGeneralErrorMessage(
					err?.message || 'Unable to load payment form.'
				);
			} );

		const form = getForm();
		const submitBtn = document.getElementById( 'place_order' );

		const preventDefault = ( evt ) => {
			evt.preventDefault();
		};

		const submitPayloadForm = async ( evt ) => {
			evt.preventDefault();

			try {
				const result = await addPaymentPaymentFormRef.current.submit();
				setPaymentMethodId( result.payment_method_id );
				removeListeners();
			} catch ( e ) {
				if ( e.data?.error_type !== 'InvalidAttributes' ) {
					setGeneralErrorMessage(
						e.data?.error_description ?? 'There was an error'
					);
				}
			}
		};

		const removeListeners = () => {
			form.removeEventListener( 'submit', preventDefault );
			submitBtn.removeEventListener( 'click', submitPayloadForm );
		};

		form.addEventListener( 'submit', preventDefault );
		submitBtn.addEventListener( 'click', submitPayloadForm );

		return removeListeners;
	}, [] );

	useEffect( () => {
		if ( paymentMethodId ) {
			const submitBtn = document.getElementById( 'place_order' );
			submitBtn.click();
		}
	}, [ paymentMethodId ] );

	if ( ! clientToken ) {
		if ( generalErrorMessage ) {
			return <div className="pl-form-error">{ generalErrorMessage }</div>;
		}
		return;
	}

	return (
		<>
			<PaymentMethodForm
				ref={ addPaymentPaymentFormRef }
				clientToken={ clientToken }
				styles={ { invalid: 'pl-input-invalid' } }
				preventDefaultOnSubmit={ true }
			>
				{ !! generalErrorMessage && (
					<div className="pl-form-error">{ generalErrorMessage }</div>
				) }
				<PaymentMethodFields />
			</PaymentMethodForm>
			<input
				type="hidden"
				name="payment_method_id"
				value={ paymentMethodId }
			/>
		</>
	);
};

const Label = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={ label } />;
};

const BlockGateway = {
	name: PAYMENT_METHOD_NAME,
	label: <Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	ariaLabel: label,
	supports: {
		// showSaveOption: true,
		// showSavedCard: true,
		features: [
			'products',
			'tokenization',
			'add_payment_method',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		],
	},
};

registerPaymentMethod( BlockGateway );

// Expose a single global that mounts the payment form ONCE (handles lazy / re-renders)
// IMPORTANT: This must remain an IIFE so that mountedContainer, currentRoot, and observer
// are shared across calls. Converting to a plain function would reset state on every invocation.
window.plMountPaymentMethodForm = ( () => {
	const TARGET_ID = 'payload-add-payment-method';

	let mountedContainer = null; // track which exact element we mounted into
	let currentRoot = null; // track the React root so we can unmount on re-renders
	let observer = null;

	const actuallyMount = ( container ) => {
		if ( ! container ) {
			return;
		}

		// If we already mounted into this exact DOM node and it's still in the DOM, do nothing.
		if (
			mountedContainer === container &&
			document.body.contains( container )
		) {
			return;
		}

		// Unmount the previous React root to clean up subscriptions and state.
		if ( currentRoot ) {
			currentRoot.unmount();
			currentRoot = null;
		}

		currentRoot = ReactDOM.createRoot( container );
		currentRoot.render( <AddPaymentMethod /> );
		mountedContainer = container;
	};

	const cleanup = () => {
		// We only remove the load listener so it doesn't fire again.
		// We deliberately KEEP the observer so we can survive checkout re-renders.
		window.removeEventListener( 'load', onLoad );
	};

	const onLoad = () => {
		const container = document.getElementById( TARGET_ID );

		if ( container ) {
			actuallyMount( container );
			cleanup();
		}
	};

	const handleFound = ( container ) => {
		if ( ! container ) {
			return;
		}

		// If everything is fully loaded, mount immediately
		if ( document.readyState === 'complete' ) {
			actuallyMount( container );
			// DO NOT clean up the observer here – we want to handle later re-renders
		}
	};

	const initObserver = () => {
		if ( observer ) {
			return;
		}

		observer = new MutationObserver( ( mutations ) => {
			for ( const mutation of mutations ) {
				for ( const node of mutation.addedNodes ) {
					if (
						node.nodeType === Node.ELEMENT_NODE &&
						node.id === TARGET_ID
					) {
						handleFound( node );
						return;
					}
				}
			}
		} );

		observer.observe( document.documentElement || document.body, {
			childList: true,
			subtree: true,
		} );
	};

	const init = () => {
		const container = document.getElementById( TARGET_ID );
		if ( container ) {
			handleFound( container );
		}

		// Ensure we mount after all assets are loaded (good for lazy templates)
		window.addEventListener( 'load', onLoad, { once: true } );

		// Watch for dynamic / lazy-loaded injection of the target container
		initObserver();
	};

	return init;
} )();

window.plMountPaymentMethodForm();
