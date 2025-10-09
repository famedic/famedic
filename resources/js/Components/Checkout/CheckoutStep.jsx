export default function CheckoutStep({
	IconComponent,
	heading,
	description,
	selectedContent,
	formContent,
	onClickEdit,
	disabled,
	error = null,
	defaultOpen = true,
}) {
	return (
		<Disclosure defaultOpen={defaultOpen}>
			{({ open }) => (
				<Card
					{...(!open ? { hoverable: true } : {})}
					className={clsx(
						"bg-zinc-50 px-4 py-6 sm:p-6 lg:p-8",
						error && "border border-red-600 dark:border-red-500",
						disabled && "pointer-events-none opacity-50",
						open
							? "dark:bg-slate-950 dark:ring-slate-900"
							: "dark:bg-slate-900",
					)}
				>
					<DisclosureButton
						onClick={onClickEdit}
						disabled={open}
						className="group w-full text-left"
					>
						{error && (
							<div className="mb-4 flex gap-2">
								<InformationCircleIcon className="size-5 flex-shrink-0 fill-red-600 dark:fill-red-500" />
								<Text>
									<span className="text-red-600 dark:text-red-500">
										{error}
									</span>
								</Text>
							</div>
						)}
						<div className="flex items-start justify-between">
							<div className="flex items-start gap-2">
								{!open ? (
									<CheckCircleIcon className="mt-0.5 size-6 flex-shrink-0 rounded-full fill-green-600 dark:fill-famedic-lime" />
								) : (
									<IconComponent
										className={clsx(
											"mt-0.5 size-6 flex-shrink-0 fill-zinc-300 sm:mt-0",
											open
												? "dark:fill-famedic-lime/40"
												: "dark:fill-slate-600",
										)}
									/>
								)}
								<div className="space-y-3">
									<Subheading
										className={
											open
												? "dark:!text-famedic-lime"
												: ""
										}
									>
										{heading}
									</Subheading>

									{!open && <div>{selectedContent}</div>}
								</div>
							</div>

							{!open && (
								<Button
									as="div"
									plain
									className="pointer-events-none"
								>
									<PencilIcon />
									Cambiar
								</Button>
							)}
						</div>
					</DisclosureButton>
					<DisclosurePanel>
						<Text>{description}</Text>

						<Divider soft className="my-6" />

						{formContent}
					</DisclosurePanel>
				</Card>
			)}
		</Disclosure>
	);
}

import { Text } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import { Subheading } from "@/Components/Catalyst/heading";
import Card from "@/Components/Card";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
import { InformationCircleIcon, PencilIcon } from "@heroicons/react/16/solid";
import { CheckCircleIcon } from "@heroicons/react/24/solid";
import clsx from "clsx";
import { Button } from "@/Components/Catalyst/button";
