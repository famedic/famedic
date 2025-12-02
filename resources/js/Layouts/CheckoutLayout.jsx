import { useMemo, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong, Anchor } from "@/Components/Catalyst/text";
import {
  ArrowPathIcon,
  PhoneIcon,
  BuildingStorefrontIcon,
  CreditCardIcon,
  InformationCircleIcon as InformationCircleIconSolid,
} from "@heroicons/react/16/solid";
import { Subheading } from "@/Components/Catalyst/heading";
import FocusedLayout from "@/Layouts/FocusedLayout";
import { Divider } from "@/Components/Catalyst/divider";
import { XMarkIcon, InformationCircleIcon } from "@heroicons/react/20/solid";
import FAQs from "@/Components/FAQs";
import FooterCopyrights from "@/Components/FooterCopyrights";
import clsx from "clsx";
import { PhotoIcon } from "@heroicons/react/24/solid";
import { CheckIcon } from "@heroicons/react/24/outline";

const emptyItemsContent = (
  <div className="text-center py-10">
    <Text className="text-zinc-500">Tu carrito está vacío.</Text>
  </div>
);

export default function CheckoutLayout({
  title,
  summaryDetails = [],
  items = [],
  children,
  header,
  paymentDisabled = false,
  onlinePaymentDisabled = false,
  branchPaymentDisabled = false,
  paymentProcessing = false,
  submit,
  showBranchPayment = false,
  data = {},
}) {
  const [isOnlineProcessing, setIsOnlineProcessing] = useState(false);
  const [isBranchProcessing, setIsBranchProcessing] = useState(false);

  const onlineDisabled = onlinePaymentDisabled !== undefined ? onlinePaymentDisabled : paymentDisabled;
  const branchDisabled = branchPaymentDisabled !== undefined ? branchPaymentDisabled : paymentDisabled;

  const paymentButtonText = useMemo(() => {
    return "Pagar ahora " + (summaryDetails[summaryDetails.length - 1]?.value || "$0");
  }, [summaryDetails]);

  const paymentBranchButtonText = useMemo(() => {
    return "Pagar en sucursal " + (summaryDetails[summaryDetails.length - 1]?.value || "$0");
  }, [summaryDetails]);

  console.log('CheckoutLayout - onlineDisabled:', onlineDisabled);
  console.log('CheckoutLayout - branchDisabled:', branchDisabled);
  console.log('CheckoutLayout - paymentProcessing:', paymentProcessing);
  console.log('CheckoutLayout - data.payment_method:', data?.payment_method);
  console.log('CheckoutLayout - showBranchPayment:', showBranchPayment);

  return (
    <FocusedLayout title={title} hideHelpBubble={true}>
      {header}

      <div className="mx-auto mt-8 grid grid-cols-1 gap-8 lg:max-w-none lg:grid-cols-5">
        <form
          onSubmit={async (e) => {
            e.preventDefault();
            const isBranch = e.nativeEvent.submitter?.name === "branch_payment";

            if (isBranch ? isBranchProcessing : isOnlineProcessing) return;

            if (isBranch) setIsBranchProcessing(true);
            else setIsOnlineProcessing(true);

            try {
              await submit(e, isBranch);
            } finally {
              setIsOnlineProcessing(false);
              setIsBranchProcessing(false);
            }
          }}
          className="flex w-full flex-col gap-8 lg:col-span-3"
        >
          {children}          
          
          {/* PAGO ONLINE */}
          <Button
            disabled={onlineDisabled || isOnlineProcessing || paymentProcessing}
            type="submit"
            name="online_payment"
            className={clsx(
              "w-full !py-3",
              (onlineDisabled || isOnlineProcessing || paymentProcessing) && "opacity-50"
            )}
          >
            <CreditCardIcon className="w-5 h-5 mr-2" />
            {paymentButtonText}
            {(isOnlineProcessing || paymentProcessing) && <ArrowPathIcon className="animate-spin ml-2 w-5 h-5" />}
          </Button>
          
          {showBranchPayment && !data?.payment_method && (
            <Text className="mt-2 text-sm text-zinc-600 dark:text-slate-400 text-center">
              <InformationCircleIconSolid className="inline w-4 h-4 mr-1" />
              Agrega una tarjeta para pagar en línea
            </Text>
          )}

          {/* PAGO EN SUCURSAL */}
          {showBranchPayment && (
            <Button
              disabled={branchDisabled || isBranchProcessing || paymentProcessing}
              type="submit"
              name="branch_payment"
              className={clsx(
                "w-full !py-3",
                (branchDisabled || isBranchProcessing || paymentProcessing) && "opacity-50"
              )}
            >
              <BuildingStorefrontIcon className="w-5 h-5 mr-2" />
              {paymentBranchButtonText}
              {(isBranchProcessing || paymentProcessing) && <ArrowPathIcon className="animate-spin ml-2 w-5 h-5" />}
            </Button>
          )}
          
          <Text className="mb-8 text-sm text-zinc-600 dark:text-slate-400">
            Al hacer clic en el botón "{paymentButtonText}",
            aceptas todos los{" "}
            <Anchor href="/terminos-y-condiciones" target="_blank" className="underline">
              Términos y condiciones
            </Anchor>{" "}
            y la{" "}
            <Anchor href="/politica-de-privacidad" target="_blank" className="underline">
              Política de privacidad
            </Anchor>
            .
          </Text>
        </form>

        <CheckoutSummary summaryDetails={summaryDetails} items={items} />
      </div>

      <Footer />
    </FocusedLayout>
  );
}

function CheckoutSummary({ summaryDetails, items }) {
    return (
        <div className="order-first mx-auto w-full lg:order-last lg:col-span-2">
            <section className="sticky top-8 space-y-6 rounded-lg bg-white px-4 py-6 shadow sm:p-6 lg:col-span-5 lg:p-8 dark:bg-slate-900">
                <div className="flow-root">
                    <ul role="list" className="[&>*:last-child]:hidden">
                        {items.length > 0 ? (
                            items.map((item, index) => (
                                <CartItem
                                    key={index}
                                    imgSrc={item.imgSrc}
                                    showDefaultImage={item.showDefaultImage}
                                    heading={item.heading}
                                    description={item.description}
                                    indications={item.indications}
                                    features={item.features || []}
                                    price={item.price}
                                    discountedPrice={item.discountedPrice}
                                    discountPercentage={item.discountPercentage}
                                    infoMessage={item.infoMessage}
                                    quantity={item.quantity}
                                    destroyCartItem={item.onDestroy}
                                />
                            ))
                        ) : (
                            <li className="py-6 sm:py-10">
                                {emptyItemsContent}
                            </li>
                        )}
                    </ul>
                </div>

                <Subheading>Resumen</Subheading>

                <dl className="[&>:first-child]:pt-0 [&>:last-child]:pb-6">
                    {summaryDetails.map((cartDetail, index) => (
                        <CartDetail
                            key={cartDetail.label}
                            {...cartDetail}
                            totalRow={index === summaryDetails.length - 1}
                        />
                    ))}
                </dl>
            </section>
        </div>
    );
}

function CartDetail({ label, value, totalRow = false }) {
    return (
        <>
            <div className="flex items-center justify-between gap-2 py-6">
                <dt>
                    {totalRow ? (
                        <Subheading
                            className={totalRow ? "dark:!text-famedic-light" : ""}
                        >
                            {label}
                        </Subheading>
                    ) : (
                        <Text>{label}</Text>
                    )}
                </dt>
                <dd>
                    <Text className="max-w-48 text-right">
                        {totalRow ? (
                            <Strong
                                className={totalRow ? "dark:!text-famedic-light" : ""}
                            >
                                {value}
                            </Strong>
                        ) : (
                            value
                        )}
                    </Text>
                </dd>
            </div>
            {!totalRow && <Divider />}
        </>
    );
}

function CartItem({
    heading,
    description,
    indications,
    features = [],
    price,
    infoMessage,
    imgSrc = null,
    showDefaultImage = true,
    discountedPrice = null,
    discountPercentage = null,
    quantity,
    destroyCartItem,
}) {
    return (
        <>
            <li className="flex pb-6">
                {(showDefaultImage || imgSrc) && (
                    <div className="flex-shrink-0">
                        {imgSrc ? (
                            <img
                                src={imgSrc}
                                className="size-20 rounded-md object-cover object-center sm:size-24"
                                alt={heading}
                            />
                        ) : (
                            <div className="flex size-20 items-center justify-center sm:size-24">
                                <PhotoIcon className="h-full fill-zinc-200 dark:fill-slate-700" />
                            </div>
                        )}
                    </div>
                )}
                <div
                    className={`w-full ${imgSrc || showDefaultImage ? "ml-4 sm:ml-6" : ""}`}
                >
                    <div className="relative flex sm:gap-x-6">
                        <div className="w-full">
                            <div className="pr-9">
                                <Subheading className="mb-3">
                                    {quantity && (
                                        <Badge color="slate">{quantity}</Badge>
                                    )}{" "}
                                    {heading}
                                </Subheading>

                                {infoMessage && (
                                    <Badge color="sky" className="mb-3">
                                        <InformationCircleIcon
                                            aria-hidden="true"
                                            className="size-5 text-famedic-light"
                                        />
                                        {infoMessage}
                                    </Badge>
                                )}

                                {description && (
                                    <Text className="sm:max-w-[80%]">
                                        <span className="text-xs">
                                            {description}
                                        </span>
                                    </Text>
                                )}

                                {indications && (
                                    <Text className="sm:max-w-[80%]">
                                        <span className="text-xs">
                                            {indications}
                                        </span>
                                    </Text>
                                )}

                                {/* Features list */}
                                {features.length > 0 && (
                                    <ul className="mt-2 space-y-1">
                                        {features.map((feature, idx) => (
                                            <li
                                                key={idx}
                                                className="flex gap-2 text-sm text-zinc-700 dark:text-slate-200"
                                            >
                                                <CheckIcon className="mt-1 size-4 flex-shrink-0 text-famedic-light" />
                                                <Text>
                                                    <span className="text-xs">
                                                        {feature}
                                                    </span>
                                                </Text>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                            {discountedPrice && discountPercentage > 0 && (
                                <Text className="mt-3 space-x-2 text-right">
                                    {discountPercentage && (
                                        <Badge color="famedic-lime">
                                            {discountPercentage}%
                                        </Badge>
                                    )}
                                    <span className="line-through">
                                        {discountedPrice}
                                    </span>
                                </Text>
                            )}

                            <Text className="mt-4 text-right">
                                <Strong>
                                    <span className="text-famedic-dark dark:text-white">
                                        {price}
                                    </span>
                                </Strong>
                            </Text>
                        </div>
                        <div className="absolute right-0 top-0">
                            <button
                                type="button"
                                onClick={destroyCartItem}
                                className="-m-2 inline-flex p-2 text-gray-400 hover:text-red-500"
                            >
                                <XMarkIcon
                                    aria-hidden="true"
                                    className="h-6 w-6"
                                />
                            </button>
                        </div>
                    </div>
                </div>
            </li>
            <Divider className="mb-6" />
        </>
    );
}

function Footer() {
    return (
        <>
            <Divider className="mb-4 mt-20" />
            <div className="flex flex-col flex-wrap items-center justify-center gap-1 sm:flex-row">
                <PhoneIcon className="hidden size-6 shrink-0 fill-zinc-950 sm:block dark:fill-white" />

                <Text>¿Necesitas ayuda? Puedes contactarnos al </Text>
                <div className="flex items-center gap-1">
                    <PhoneIcon className="size-6 shrink-0 fill-zinc-950 sm:hidden dark:fill-white" />

                    <Anchor href="tel:8128601893">81 2860 1893</Anchor>
                </div>
            </div>
            <Divider className="mb-4 mt-4" />
            <div className="space-y-4">
                <FAQs
                    faqs={[
                        {
                            question:
                                "¿Qué datos personales necesitamos para procesar tu compra?",
                            answer: "Recopilamos tus datos de identificación, como nombre y correo electrónico, y tus datos de contacto para completar tu compra de manera segura y eficiente.",
                        },
                        {
                            question:
                                "¿Qué hacemos con tu información de pago?",
                            answer: "Utilizamos tus datos de pago exclusivamente para procesar tu compra a través de proveedores confiables, como instituciones bancarias, garantizando seguridad en cada transacción.",
                        },
                        {
                            question:
                                "¿Se comparte tu información personal con terceros?",
                            answer: "Sí, pero solo con proveedores confiables, como bancos o servicios logísticos, para garantizar que recibas tu pedido de manera correcta y segura. No compartimos tus datos con terceros no autorizados.",
                        },
                        {
                            question:
                                "¿Cómo protegemos tu información durante el proceso de compra?",
                            answer: "Utilizamos medidas de seguridad avanzadas para proteger tus datos personales y de pago contra cualquier uso no autorizado.",
                        },
                        {
                            question:
                                "¿Puedes limitar el uso de tus datos para fines adicionales?",
                            answer: "Claro, puedes optar por no recibir notificaciones promocionales o de marketing, pero aún tendrás acceso a nuestros servicios y productos.",
                        },
                    ]}
                />
                <Divider />
                <div className="pb-8">
                    <FooterCopyrights />
                </div>
            </div>
        </>
    );
}