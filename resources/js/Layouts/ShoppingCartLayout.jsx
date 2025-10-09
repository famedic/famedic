export default function ShoppingCartLayout({
	title,
	header,
	items,
	summaryDetails,
	summaryInfoMessage,
	checkoutUrl,
	emptyItemsContent,
	children,
}) {
	return (
		<>
			<FamedicLayout
				title={title}
				navbar={<NavBar />}
				sidebar={<SideBar />}
			>
				{header}

				<div className="lg:grid lg:grid-cols-12 lg:items-start lg:gap-x-12 xl:gap-x-16">
					<section className="lg:col-span-7">
						<ul role="list">
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
										discountPercentage={
											item.discountPercentage
										}
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
					</section>

					{items.length > 0 && (
						<CartSummary
							cartDetails={summaryDetails}
							checkoutUrl={checkoutUrl}
							infoMessage={summaryInfoMessage}
						/>
					)}
				</div>

				{children}
			</FamedicLayout>
		</>
	);
}

function CartItem({
	heading,
	description = null,
	indications = null,
	features = [],
	price,
	infoMessage,
	imgSrc = null,
	showDefaultImage = true,
	discountedPrice = null,
	discountPercentage = null,
	destroyCartItem,
	quantity = null,
}) {
	return (
		<>
			<Divider />
			<li className="flex py-6 sm:py-10">
				{(showDefaultImage || imgSrc) && (
					<div className="flex-shrink-0">
						{imgSrc ? (
							<img
								src={imgSrc}
								className="size-24 rounded-md object-cover object-center sm:size-48"
							/>
						) : (
							<div className="flex size-24 items-center justify-center rounded-lg bg-zinc-100 sm:size-48 dark:bg-slate-800">
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
										{description}
									</Text>
								)}

								{indications && (
									<Text className="sm:max-w-[80%]">
										{indications}
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
												<Text>{feature}</Text>
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
									<span className="text-2xl text-famedic-dark dark:text-white">
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
		</>
	);
}

function CartSummary({ cartDetails, checkoutUrl, infoMessage }) {
	return (
		<Card className="sticky mt-16 space-y-6 px-4 py-6 sm:p-6 lg:top-24 lg:col-span-5 lg:mt-0 lg:p-8">
			{infoMessage?.title && infoMessage?.message && (
				<div className="rounded-md bg-slate-50 p-4 shadow dark:bg-slate-800">
					<div className="flex">
						<div className="shrink-0">
							<InformationCircleIcon
								aria-hidden="true"
								className="size-6 text-famedic-light"
							/>
						</div>
						<div className="ml-3">
							<Subheading>Necesitarás una cita</Subheading>

							<Text className="mt-2">
								Algunos estudios requieren cita para asegurar
								que la sucursal cuente con el equipo necesario y
								que se cumplan todos los requisitos. Esto
								garantiza un servicio preciso y de calidad.
							</Text>
						</div>
					</div>
				</div>
			)}

			<Heading>Resumen</Heading>

			<dl className="[&>:first-child]:pt-0 [&>:last-child]:pb-6">
				{cartDetails.map((cartDetail, index) => (
					<CartDetail
						key={cartDetail.label}
						{...cartDetail}
						totalRow={index === cartDetails.length - 1}
					/>
				))}
			</dl>

			<Button
				href={checkoutUrl}
				className="w-full animate-pulse !py-3 hover:animate-none"
			>
				<ChevronDoubleRightIcon />
				Continuar
			</Button>
		</Card>
	);
}

function CartDetail({ label, value, totalRow = false }) {
	return (
		<>
			<div className="flex items-center justify-between gap-4 py-6">
				<dt className="flex-shrink-0">
					{totalRow ? (
						<Subheading>
							<span className="whitespace-nowrap text-2xl">
								{label}
							</span>
						</Subheading>
					) : (
						<Text className="whitespace-nowrap">{label}</Text>
					)}
				</dt>
				<dd className="text-right">
					{totalRow ? (
						<Strong className="text-2xl">{value}</Strong>
					) : (
						<Text className="text-balance">{value}</Text>
					)}
				</dd>
			</div>
			{!totalRow && <Divider />}
		</>
	);
}

import FamedicLayout from "@/Layouts/FamedicLayout";
import SideBar from "@/Layouts/FamedicLayout/SideBar";
import NavBar from "@/Layouts/FamedicLayout/NavBar";
import { Divider } from "@/Components/Catalyst/divider";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { XMarkIcon, InformationCircleIcon } from "@heroicons/react/20/solid";
import { ChevronDoubleRightIcon } from "@heroicons/react/16/solid";
import { CheckIcon, PhotoIcon } from "@heroicons/react/24/solid";
import Card from "@/Components/Card";
