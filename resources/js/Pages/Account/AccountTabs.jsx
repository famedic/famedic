import { useEffect, useMemo, useState } from "react";
import clsx from "clsx";
import { usePage } from "@inertiajs/react";
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from "@/Components/Catalyst/tabs";
import BasicInfoForm from "@/Pages/Account/BasicInfoForm";
import ContactInfoForm from "@/Pages/Account/ContactInfoForm";
import UpdatePasswordForm from "@/Pages/Account/UpdatePasswordForm";

const TAB_KEYS = ["basic", "contact", "password"];

const TAB_DEFINITIONS = [
	{ key: "basic", label: "Información básica", dusk: "accountTabBasic" },
	{ key: "contact", label: "Contacto", dusk: "accountTabContact" },
	{ key: "password", label: "Contraseña", dusk: "accountTabPassword" },
];

function resolveInitialTabIndex(mustVerifyContact) {
	if (typeof window === "undefined") {
		return mustVerifyContact ? 1 : 0;
	}

	const tab = new URLSearchParams(window.location.search).get("tab");
	const fromUrl = TAB_KEYS.indexOf(tab);
	if (fromUrl >= 0) {
		return fromUrl;
	}

	return mustVerifyContact ? 1 : 0;
}

function tabButtonClass(selected) {
	return clsx(
		"w-full rounded-lg px-3 py-2.5 text-sm font-medium transition sm:px-4",
		selected
			? "bg-famedic-dark text-white shadow-sm dark:bg-zinc-100 dark:text-zinc-900"
			: "text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800",
	);
}

export default function AccountTabs() {
	const { mustVerifyEmail, mustVerifyPhone } = usePage().props;
	const mustVerifyContact = Boolean(mustVerifyEmail || mustVerifyPhone);
	const [activeTab, setActiveTab] = useState(() => resolveInitialTabIndex(mustVerifyContact));

	const tabs = useMemo(
		() =>
			TAB_DEFINITIONS.map((tab) => ({
				...tab,
				showBadge: tab.key === "contact" && mustVerifyContact,
			})),
		[mustVerifyContact],
	);

	useEffect(() => {
		const tab = TAB_KEYS[activeTab];
		if (!tab) return;

		const url = new URL(window.location.href);
		if (url.searchParams.get("tab") === tab) return;

		url.searchParams.set("tab", tab);
		window.history.replaceState({}, "", url);
	}, [activeTab]);

	return (
		<TabGroup selectedIndex={activeTab} onChange={setActiveTab}>
			<TabList
				className="gap-1 overflow-x-auto rounded-xl border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-950/30 sm:grid sm:grid-cols-3 sm:overflow-visible"
				aria-label="Secciones de mi cuenta"
			>
				{tabs.map((tab) => (
					<Tab key={tab.key} className="min-w-[9.5rem] shrink-0 sm:min-w-0">
						{(selected) => (
							<div
								className={tabButtonClass(selected)}
								dusk={tab.dusk}
								data-tab={tab.key}
							>
								<span className="inline-flex items-center justify-center gap-2">
									{tab.label}
									{tab.showBadge && (
										<span
											className="size-2 rounded-full bg-red-500"
											title="Verificación pendiente"
											aria-hidden
										/>
									)}
								</span>
							</div>
						)}
					</Tab>
				))}
			</TabList>

			<TabPanels className="mt-6">
				<TabPanel className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-6 dark:border-zinc-700 dark:bg-zinc-900/40">
					<BasicInfoForm />
				</TabPanel>
				<TabPanel className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-6 dark:border-zinc-700 dark:bg-zinc-900/40">
					<ContactInfoForm />
				</TabPanel>
				<TabPanel className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-6 dark:border-zinc-700 dark:bg-zinc-900/40">
					<UpdatePasswordForm />
				</TabPanel>
			</TabPanels>
		</TabGroup>
	);
}
