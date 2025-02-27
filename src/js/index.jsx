import React, { useEffect, useRef, useState } from "react";
import { decodeEntities } from "@wordpress/html-entities";
import { PaymentForm, PaymentMethodForm, Card } from "payload-react";

import "../css/style.scss";

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;

const settings = getSetting("payload", {});
const label =
  decodeEntities(settings.title) ||
  window.wp.i18n.__("Credit/Debit Card", "payload");

const Content = (props) => {
  const { eventRegistration, emitResponse, billing } = props;
  const { onPaymentSetup } = eventRegistration;
  const [clientToken, setClientToken] = useState();
  const paymentFormRef = useRef(null);
  const hasSubscription = !!props.cartData.extensions?.subscriptions?.length;

  console.log(props);
  useEffect(() => {
    wp.apiFetch({ path: "wc/v3/custom" }).then((data) =>
      setClientToken(data.client_token)
    );

    const unsubscribe = onPaymentSetup(async () => {
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
      } catch (e) {
        console.log(e, e.details);

        return {
          type: emitResponse.responseTypes.ERROR,
          message: "There was an error",
        };
      }
    });

    // Unsubscribes when this component is unmounted.
    return () => {
      unsubscribe();
    };
  }, [
    emitResponse.responseTypes.ERROR,
    emitResponse.responseTypes.SUCCESS,
    onPaymentSetup,
  ]);

  return (
    <>
      {decodeEntities(settings.description || "")}
      <PaymentForm
        ref={paymentFormRef}
        clientToken={clientToken}
        styles={{ invalid: "is-invalid" }}
        preventDefaultOnSubmit={true}
        payment={{
          amount: billing.cartTotal.value / 100,
          payment_method: {
            keep_active: hasSubscription,
          },
        }}
      >
        <Card
          className="payload-card-input"
          onInvalid={(evt) => {
            alert(evt.message);
          }}
        />
      </PaymentForm>
    </>
  );
};

const AddPaymentMethod = () => {
  const [clientToken, setClientToken] = useState();
  const [paymentMethodId, setPaymentMethodId] = useState();
  const addPaymentPaymentFormRef = useRef(null);

  useEffect(() => {
    wp.apiFetch({ path: "wc/v3/custom" }).then((data) =>
      setClientToken(data.client_token)
    );

    const orderReview = document.getElementById("order_review");
    const placeOrder = document.getElementById("place_order");

    const preventDefault = (evt) => {
      evt.preventDefault();
    };

    const submitPayloadForm = async (evt) => {
      evt.preventDefault();

      try {
        const result = await addPaymentPaymentFormRef.current.submit();
        setPaymentMethodId(result.payment_method_id);
        removeListeners();
      } catch (e) {
        console.log(e, e.details);
      }
    };

    const removeListeners = () => {
      orderReview.removeEventListener("submit", preventDefault);
      placeOrder.removeEventListener("click", submitPayloadForm);
    };

    orderReview.addEventListener("submit", preventDefault);
    placeOrder.addEventListener("click", submitPayloadForm);

    return removeListeners;
  }, []);

  useEffect(() => {
    if (paymentMethodId) {
      const placeOrder = document.getElementById("place_order");
      placeOrder.click();
    }
  }, [paymentMethodId]);

  return (
    <>
      <PaymentMethodForm
        ref={addPaymentPaymentFormRef}
        clientToken={clientToken}
        styles={{ invalid: "is-invalid" }}
        preventDefaultOnSubmit={true}
      >
        <Card
          className="payload-card-input"
          onInvalid={(evt) => {
            alert(evt.message);
          }}
        />
      </PaymentMethodForm>
      <input type="hidden" name="payment_method_id" value={paymentMethodId} />
    </>
  );
};

const Label = (props) => {
  const { PaymentMethodLabel } = props.components;
  return <PaymentMethodLabel text={label} />;
};

const Block_Gateway = {
  name: "payload",
  label: <Label />,
  content: <Content />,
  edit: <Content />,
  canMakePayment: () => true,
  ariaLabel: label,
  supports: {
    // showSaveOption: true,
    // showSavedCard: true,
    features: [
      "products",
      "tokenization",
      "add_payment_method",
      "subscriptions",
      "subscription_cancellation",
      "subscription_suspension",
      "subscription_reactivation",
      "subscription_amount_changes",
      "subscription_date_changes",
      "subscription_payment_method_change",
      "subscription_payment_method_change_customer",
      "subscription_payment_method_change_admin",
      "multiple_subscriptions",
    ],
  },
};

registerPaymentMethod(Block_Gateway);

if (document.querySelector("#payload-add-payment-method")) {
  const domContainer = document.querySelector("#payload-add-payment-method");
  ReactDOM.render(<AddPaymentMethod />, domContainer);
}
