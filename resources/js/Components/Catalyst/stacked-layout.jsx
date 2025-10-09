import * as Headless from "@headlessui/react";
import { useState } from "react";
import { NavbarItem } from "./navbar";
import {
	ArrowRightIcon,
	Bars2Icon,
	XMarkIcon,
} from "@heroicons/react/20/solid";
import Footer from "@/Layouts/FamedicLayout/Footer";
import { Subheading } from "@/Components/Catalyst/heading";
import { usePage } from "@inertiajs/react";
import { Text, TextLink } from "@/Components/Catalyst/text";

export function StackedLayout({ navbar, sidebar, children }) {
	let [showSidebar, setShowSidebar] = useState(false);
	const { mainNavigation } = usePage().props;

	return (
		<div className="relative isolate flex min-h-svh flex-col bg-zinc-50 dark:bg-slate-950">
			{/* Sidebar on mobile */}
			<MobileSidebar
				open={showSidebar}
				close={() => setShowSidebar(false)}
			>
				{sidebar}
			</MobileSidebar>

			{/* Navbar */}
			<header className="sticky top-0 z-10">
				<div className="flex items-center justify-center rounded-none border-b border-slate-200 bg-white/90 px-4 backdrop-blur-sm lg:px-8 dark:border-slate-800 dark:bg-slate-900/90">
					<div className="lg:hidden">
						<NavbarItem
							onClick={() => setShowSidebar(true)}
							aria-label="Open navigation"
						>
							<Bars2Icon className="!fill-famedic-darker dark:!fill-white" />
						</NavbarItem>
					</div>
					<div className="flex max-w-[100rem] flex-1">{navbar}</div>
				</div>
			</header>

			{/* Content */}
			<div className="mx-auto flex w-full max-w-[100rem] flex-1 flex-col px-4 lg:px-8">
				<main className="mb-12 flex flex-1 flex-col">
					<div className="grow rounded-2xl">{children}</div>
				</main>

				<Footer
					links={
						<div>
							<Subheading>Servicios</Subheading>

							<ul role="list" className="mt-6 space-y-4">
								{mainNavigation.map((item) => (
									<li key={item.label}>
										<Text>
											<TextLink
												className="group flex items-center no-underline hover:underline"
												href={item.url}
											>
												{item.label}
												<ArrowRightIcon className="ml-1 size-5 opacity-0 transition-all duration-300 group-hover:opacity-100" />
											</TextLink>
										</Text>
									</li>
								))}
							</ul>
						</div>
					}
				/>
			</div>
		</div>
	);
}

function MobileSidebar({ open, close, children }) {
	return (
		<Headless.Dialog open={open} onClose={close} className="lg:hidden">
			<Headless.DialogBackdrop
				transition
				className="fixed inset-0 bg-black/30 transition data-[closed]:opacity-0 data-[enter]:duration-300 data-[leave]:duration-200 data-[enter]:ease-out data-[leave]:ease-in"
			/>
			<Headless.DialogPanel
				transition
				className="fixed inset-y-0 w-full max-w-80 p-2 transition duration-300 ease-in-out data-[closed]:-translate-x-full"
			>
				<div className="flex h-full flex-col rounded-lg bg-white shadow-sm ring-1 ring-zinc-950/5 dark:bg-slate-900 dark:ring-white/10">
					<div className="-mb-3 px-4 pt-3">
						<Headless.CloseButton
							as={NavbarItem}
							aria-label="Close navigation"
						>
							<XMarkIcon />
						</Headless.CloseButton>
					</div>
					{children}
				</div>
			</Headless.DialogPanel>
		</Headless.Dialog>
	);
}
