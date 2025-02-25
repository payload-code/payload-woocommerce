import React, { useEffect, useRef, useState } from "react";
import { decodeEntities } from "@wordpress/html-entities";
import { PaymentForm, PayloadInput, Card } from "payload-react";

import "../css/style.scss";

const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;

const settings = getSetting("payload_data", {});
const label =
  decodeEntities(settings.title) ||
  window.wp.i18n.__("Credit/Debit Card", "payload");

const Content = (props) => {
  const { eventRegistration, emitResponse, billing } = props;
  const { onPaymentSetup } = eventRegistration;
  const [clientToken, setClientToken] = useState();
  const paymentFormRef = useRef(null);

  console.log(props);
  useEffect(() => {
    wp.apiFetch({ path: "wc/v3/custom" }).then((data) =>
      setClientToken(data.client_token)
    );

    const unsubscribe = onPaymentSetup(async () => {
      let result;
      try {
        result = await paymentFormRef.current.submit();
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
        result = e.details;

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
        payment={{ amount: billing.cartTotal.value / 100 }}
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
    features: settings.supports,
  },
};

registerPaymentMethod(Block_Gateway);
