export default function FAQs({ className, faqs }) {
	return (
		<Disclosure as="div" className={className}>
			<DisclosureButton className="group flex w-full items-center justify-between">
				<Subheading className="group-data-[hover]:opacity-70">
					Preguntas frecuentes
				</Subheading>
				<ChevronDownIcon className="size-5 fill-zinc-950 group-data-[open]:rotate-180 group-data-[hover]:opacity-70 dark:fill-white" />
			</DisclosureButton>
			<DisclosurePanel className="mb-6 mt-2 text-sm/5 text-black/50">
				<div className="w-full divide-y divide-zinc-950/5 rounded-xl bg-zinc-50 dark:divide-white/5 dark:bg-white/5">
					{faqs.map((faq) => (
						<Disclosure key={faq.question} as="div" className="p-6">
							<DisclosureButton className="group flex w-full items-center justify-between">
								<Text className="group-data-[hover]:opacity-70">
									<Strong>{faq.question}</Strong>
								</Text>
								<ChevronDownIcon className="size-5 fill-zinc-950 group-data-[open]:rotate-180 group-data-[hover]:opacity-70 dark:fill-white" />
							</DisclosureButton>
							<DisclosurePanel className="mt-2">
								<Text className="max-w-3xl">{faq.answer}</Text>
							</DisclosurePanel>
						</Disclosure>
					))}
				</div>
			</DisclosurePanel>
		</Disclosure>
	);
}

import { Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { ChevronDownIcon } from "@heroicons/react/20/solid";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
