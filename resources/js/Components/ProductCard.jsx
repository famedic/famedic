import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import {
	ShoppingCartIcon,
	MinusCircleIcon,
	PlusCircleIcon,
	BarsArrowDownIcon,
	ArrowPathIcon,
	EyeIcon,
} from "@heroicons/react/16/solid";
import { PhotoIcon, CheckIcon } from "@heroicons/react/24/solid";
import { Button } from "@/Components/Catalyst/button";
import { Link } from "@inertiajs/react";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
import { useRef, useEffect, useState } from "react";
import { Dialog, DialogBody } from "@/Components/Catalyst/dialog";

export default function ProductCard({
	heading,
	description,
	inCartHref = null,
	discountPercentage,
	discountedPrice,
	tags = [],
	price,
	quantity = null,
	imgSrc,
	showDefaultImage = false,
	onRemoveClick,
	onAddClick,
	onQuantityChangeClick,
	features = [],
	processing = false,
	// Laboratory test specific fields
	otherName = null,
	elements = null,
	commonUse = null,
}) {
	const [isOpen, setIsOpen] = useState(
		route().current && route().current("laboratory-tests.test"),
	);

	return (
		<>
			<div className="flex flex-1 flex-col rounded-xl shadow-md">
				<ProductCardContent
					heading={heading}
					description={description}
					tags={tags}
					features={features}
					imgSrc={imgSrc}
					showDefaultImage={showDefaultImage}
					price={price}
					discountedPrice={discountedPrice}
					discountPercentage={discountPercentage}
					quantity={quantity}
					inCartHref={inCartHref}
					onRemoveClick={onRemoveClick}
					onAddClick={onAddClick}
					onQuantityChangeClick={onQuantityChangeClick}
					processing={processing}
					showDetailButton={true}
					setIsOpen={setIsOpen}
					otherName={otherName}
					elements={elements}
					commonUse={commonUse}
				/>
			</div>

			<ProductDetail
				heading={heading}
				description={description}
				tags={tags}
				features={features}
				imgSrc={imgSrc}
				showDefaultImage={showDefaultImage}
				price={price}
				discountedPrice={discountedPrice}
				discountPercentage={discountPercentage}
				quantity={quantity}
				inCartHref={inCartHref}
				onRemoveClick={onRemoveClick}
				onAddClick={onAddClick}
				onQuantityChangeClick={onQuantityChangeClick}
				processing={processing}
				showDetailButton={true}
				setIsOpen={setIsOpen} // pass setIsOpen as prop
				isOpen={isOpen}
				otherName={otherName}
				elements={elements}
				commonUse={commonUse}
			/>
		</>
	);
}

function ProductCardContent({
	heading,
	description,
	tags = [],
	features = [],
	imgSrc,
	showDefaultImage = false,
	price,
	discountedPrice,
	discountPercentage,
	quantity = null,
	inCartHref = null,
	onRemoveClick,
	onAddClick,
	onQuantityChangeClick,
	processing = false,
	showDetailButton = false,
	setIsOpen,
	forceExpand = false,
	// Laboratory test specific fields
	otherName = null,
	elements = null,
	commonUse = null,
}) {
	const descRef = useRef(null);
	const [showExpand, setShowExpand] = useState(false);

	useEffect(() => {
		if (forceExpand) {
			setShowExpand(false);
			return;
		}
		function checkEllipsis() {
			const el = descRef.current;
			if (!el) return;
			setShowExpand(el.scrollHeight > el.clientHeight + 1);
		}
		checkEllipsis();
		window.addEventListener("resize", checkEllipsis);
		return () => window.removeEventListener("resize", checkEllipsis);
	}, [description, forceExpand]);

	return (
		<>
			{/* In-cart header */}
			{inCartHref && (
				<div className="flex items-center justify-center rounded-t-xl bg-slate-100 py-2.5 dark:bg-slate-800">
					<CheckIcon className="mr-1 h-4 w-4 stroke-famedic-light" />
					<span className="text-sm font-semibold text-famedic-dark dark:text-white">
						En{" "}
						<Link className="underline" href={inCartHref}>
							tu carrito
						</Link>
					</span>
				</div>
			)}
			<div
				className={`h-full ${inCartHref ? "rounded-b-xl bg-slate-100 px-1.5 pb-1.5 dark:bg-slate-800" : ""}`}
			>
				<Card className="flex h-full flex-1 flex-col justify-between gap-6 p-6 lg:gap-8">
					{/* Body */}
					<div className="space-y-3">
						{/* Image */}
						{(showDefaultImage || imgSrc) && (
							<div className="mb-6 aspect-1 w-full rounded-lg lg:mb-8">
								{imgSrc ? (
									<img
										src={imgSrc}
										alt={heading}
										className="h-full w-full rounded-lg bg-transparent object-cover"
									/>
								) : (
									<div className="flex h-full w-full items-center justify-center">
										<PhotoIcon className="size-32 fill-zinc-200 dark:fill-slate-700" />
									</div>
								)}
							</div>
						)}

						{/* Heading */}
						<Subheading>{heading}</Subheading>

						{/* Tags */}
						{tags?.length > 0 && (
							<div className="flex flex-wrap gap-2">
								{tags.map((tag) => (
									<Badge
										key={tag.label}
										color={tag.color || "slate"}
									>
										<tag.icon className="size-4" />
										{tag.label}
									</Badge>
								))}
							</div>
						)}

						{/* Description */}
						<Disclosure defaultOpen={forceExpand}>
							{({ open }) => (
								<div>
									{!open && (
										<Text className="line-clamp-4">
											<span
												ref={descRef}
												className="block"
											>
												{description}
											</span>
										</Text>
									)}
									{showExpand && !open && !forceExpand && (
										<DisclosureButton
											as="div"
											className="mt-3 flex w-full items-center justify-center gap-1"
										>
											<Button outline>
												<BarsArrowDownIcon className="size-4" />
											</Button>
										</DisclosureButton>
									)}
									<DisclosurePanel>
										<Text>{description}</Text>
									</DisclosurePanel>
								</div>
							)}
						</Disclosure>

						{/* Features list */}
						{features.length > 0 && (
							<ul className="space-y-1">
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

						{forceExpand && (
							<>
								{otherName && forceExpand && (
									<div>
										<Text className="!text-xs">
											<Strong>
												También conocido como
											</Strong>
										</Text>
										<Text className="text-balance">
											{otherName}
										</Text>
									</div>
								)}
								{/* Elements for laboratory tests - only show in detail dialog */}
								{elements && forceExpand && (
									<div>
										<Text className="!text-xs">
											<Strong>
												Elementos analizados
											</Strong>
										</Text>
										<Text className="text-balance">
											{elements}
										</Text>
									</div>
								)}
								{/* Common use for laboratory tests - only show in detail dialog */}
								{commonUse && forceExpand && (
									<div>
										<Text className="!text-xs">
											<Strong>Usos comunes</Strong>
										</Text>
										<Text className="text-balance">
											{commonUse}
										</Text>
									</div>
								)}
							</>
						)}
					</div>

					{/* Price */}
					<div>
						<div className="mb-2">
							{discountedPrice && discountPercentage > 0 && (
								<Text className="space-x-2">
									{discountPercentage && (
										<Badge color="lime">
											{discountPercentage}%
										</Badge>
									)}
									<span className="line-through">
										{discountedPrice}
									</span>
								</Text>
							)}
							<Text>
								<Strong>
									<span className="text-2xl text-famedic-dark dark:text-white">
										{price}
									</span>
								</Strong>
							</Text>
						</div>

						{/* Cart actions */}
						{inCartHref ? (
							<>
								{!!quantity && (
									<div className="mb-4 mt-3 flex items-center gap-1">
										<Button
											onClick={() =>
												quantity === 1
													? onRemoveClick()
													: onQuantityChangeClick(
															quantity - 1,
														)
											}
											plain
										>
											<MinusCircleIcon className="!size-6 fill-red-600 dark:fill-red-400" />
										</Button>
										<Subheading>
											<span className="px-2 text-xl">
												{quantity}
											</span>
										</Subheading>
										<Button
											onClick={() =>
												onQuantityChangeClick(
													quantity + 1,
												)
											}
											plain
										>
											<PlusCircleIcon className="!size-6 fill-green-600 dark:fill-green-400" />
										</Button>
									</div>
								)}
								<Button
									type="button"
									onClick={onRemoveClick}
									outline
									className="w-full"
								>
									<ShoppingCartIcon className="h-6 w-6 fill-red-300" />
									Eliminar
								</Button>
							</>
						) : (
							<>
								<Button
									type="button"
									color="famedic-light"
									className="hidden w-full dark:flex"
									onClick={onAddClick}
									disabled={processing}
								>
									<ShoppingCartIcon className="h-6 w-6 fill-famedic-lime" />
									Agregar
									{processing && (
										<ArrowPathIcon className="animate-spin" />
									)}
								</Button>
								<Button
									type="button"
									className="flex w-full dark:hidden"
									onClick={onAddClick}
									disabled={processing}
								>
									<ShoppingCartIcon className="h-6 w-6" />
									Agregar
									{processing && (
										<ArrowPathIcon className="animate-spin" />
									)}
								</Button>
							</>
						)}

						{/* Detail button */}
						{showDetailButton && (
							<Button
								plain
								type="button"
								className="mt-2 w-full"
								onClick={() => setIsOpen(true)}
							>
								<EyeIcon />
								Ver detalle
							</Button>
						)}
					</div>
				</Card>
			</div>
		</>
	);
}

function ProductDetail({
	heading,
	description,
	tags = [],
	features = [],
	imgSrc,
	showDefaultImage = false,
	price,
	discountedPrice,
	discountPercentage,
	quantity = null,
	inCartHref = null,
	onRemoveClick,
	onAddClick,
	onQuantityChangeClick,
	processing = false,
	setIsOpen,
	isOpen,
	// Laboratory test specific fields
	otherName = null,
	elements = null,
	commonUse = null,
}) {
	// Wrap onRemoveClick to close dialog after removing
	const handleRemoveClick = () => {
		if (onRemoveClick) onRemoveClick();
		if (setIsOpen) setIsOpen(false);
	};

	return (
		<Dialog open={isOpen} onClose={() => setIsOpen(false)} className="!p-0">
			<DialogBody className="!mt-0">
				<ProductCardContent
					heading={heading}
					description={description}
					tags={tags}
					features={features}
					imgSrc={imgSrc}
					showDefaultImage={showDefaultImage}
					price={price}
					discountedPrice={discountedPrice}
					discountPercentage={discountPercentage}
					quantity={quantity}
					inCartHref={inCartHref}
					onRemoveClick={handleRemoveClick}
					onAddClick={onAddClick}
					onQuantityChangeClick={onQuantityChangeClick}
					processing={processing}
					setIsOpen={setIsOpen}
					forceExpand={true} // always expand in dialog
					otherName={otherName}
					elements={elements}
					commonUse={commonUse}
				/>
			</DialogBody>
		</Dialog>
	);
}
