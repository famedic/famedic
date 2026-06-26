import Card from "@/Components/Card";
import { Text } from "@/Components/Catalyst/text";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
import { ChevronDownIcon } from "@heroicons/react/20/solid";
import clsx from "clsx";

export default function MembershipFAQ({ faq = [] }) {
	if (faq.length === 0) {
		return null;
	}

	return (
		<section className="space-y-4">
			<div>
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Preguntas frecuentes
				</h3>
				<Text className="text-sm text-zinc-500 dark:text-slate-400">
					Respuestas rápidas sobre tu membresía.
				</Text>
			</div>

			<Card className="divide-y divide-slate-100 overflow-hidden shadow-sm ring-1 ring-slate-100 dark:divide-slate-800 dark:ring-slate-700/80">
				{faq.map((item) => (
					<Disclosure key={item.question} as="div">
						{({ open }) => (
							<>
								<DisclosureButton className="flex w-full items-center justify-between gap-4 px-5 py-4 text-left transition-colors hover:bg-slate-50 dark:hover:bg-slate-800/40">
									<span className="font-medium text-zinc-900 dark:text-white">
										{item.question}
									</span>
									<ChevronDownIcon
										className={clsx(
											"size-5 shrink-0 text-zinc-400 transition-transform",
											open && "rotate-180",
										)}
									/>
								</DisclosureButton>
								<DisclosurePanel className="px-5 pb-4">
									<Text className="text-sm leading-relaxed text-zinc-600 dark:text-slate-300">
										{item.answer}
									</Text>
								</DisclosurePanel>
							</>
						)}
					</Disclosure>
				))}
			</Card>
		</section>
	);
}
