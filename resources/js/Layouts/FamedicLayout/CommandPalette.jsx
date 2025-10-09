import {
	Combobox,
	ComboboxInput,
	ComboboxOption,
	ComboboxOptions,
	Dialog,
	DialogPanel,
	DialogBackdrop,
} from "@headlessui/react";
import { MagnifyingGlassIcon } from "@heroicons/react/20/solid";
import {
	FolderIcon,
	PhoneIcon,
	HashtagIcon,
	TagIcon,
	ArrowRightStartOnRectangleIcon,
} from "@heroicons/react/24/outline";
import { useState } from "react";

const projects = [
	{ id: 1, name: "Marcar a soporte", url: "#" },
	// More projects...
];
const recent = [projects[0]];
const quickActions = [
	{
		name: "Buscar ayuda en documentación...",
		icon: MagnifyingGlassIcon,

		url: "#",
	},
	{
		name: "Buscar en farmacia...",
		icon: MagnifyingGlassIcon,
	},
	{
		name: "Buscar en laboratorios...",
		icon: MagnifyingGlassIcon,
	},
	{
		name: "Cerrar sessión",
		icon: ArrowRightStartOnRectangleIcon,
	},
];

export default function Example({
	commandPaletteIsOpen,
	setCommandPaletteIsOpen,
}) {
	const [query, setQuery] = useState("");

	const filteredProjects =
		query === ""
			? []
			: projects.filter((project) => {
					return project.name
						.toLowerCase()
						.includes(query.toLowerCase());
				});

	return (
		<Dialog
			className="relative z-10"
			open={commandPaletteIsOpen}
			onClose={() => {
				setCommandPaletteIsOpen(false);
				setQuery("");
			}}
		>
			<DialogBackdrop
				transition
				className="fixed inset-0 bg-gray-500 bg-opacity-25 transition-opacity data-[closed]:opacity-0 data-[enter]:duration-300 data-[leave]:duration-200 data-[enter]:ease-out data-[leave]:ease-in"
			/>

			<div className="fixed inset-0 z-10 w-screen overflow-y-auto p-4 sm:p-6 md:p-20">
				<DialogPanel
					transition
					className="mx-auto max-w-2xl transform divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-black ring-opacity-5 transition-all data-[closed]:scale-95 data-[closed]:opacity-0 data-[enter]:duration-300 data-[leave]:duration-200 data-[enter]:ease-out data-[leave]:ease-in"
				>
					<Combobox
						onChange={(item) => {
							if (item) {
								window.location = item.url;
							}
						}}
					>
						<div className="relative">
							<MagnifyingGlassIcon
								className="pointer-events-none absolute left-4 top-3.5 h-5 w-5 text-gray-400"
								aria-hidden="true"
							/>
							<ComboboxInput
								autoFocus
								className="h-12 w-full border-0 bg-transparent pl-11 pr-4 text-gray-900 placeholder:text-gray-400 focus:ring-0 sm:text-sm"
								placeholder="Search..."
								onChange={(event) =>
									setQuery(event.target.value)
								}
								onBlur={() => setQuery("")}
							/>
						</div>

						{(query === "" || projects.length > 0) && (
							<ComboboxOptions
								static
								as="ul"
								className="max-h-80 scroll-py-2 divide-y divide-gray-100 overflow-y-auto"
							>
								<li className="p-2">
									{true && (
										<h2 className="mb-2 mt-4 px-3 text-xs font-semibold text-gray-500">
											Ayuda
										</h2>
									)}
									<ul className="text-sm text-gray-700">
										{recent.map((project) => (
											<ComboboxOption
												as="li"
												key={project.id}
												value={project}
												className="group flex cursor-default select-none items-center rounded-md px-3 py-2 data-[focus]:bg-famedic-dark data-[focus]:text-white"
											>
												<PhoneIcon
													className="h-6 w-6 flex-none text-famedic-light group-data-[focus]:text-white"
													aria-hidden="true"
												/>
												<span className="ml-3 flex-auto truncate">
													{project.name}
												</span>
												<span className="ml-3 hidden flex-none text-indigo-100 group-data-[focus]:inline">
													81-1234-5678
												</span>
											</ComboboxOption>
										))}
									</ul>
								</li>
								{true && (
									<li className="p-2">
										<h2 className="sr-only">
											Quick actions
										</h2>
										<ul className="text-sm text-gray-700">
											{quickActions.map((action) => (
												<ComboboxOption
													as="li"
													key={action.shortcut}
													value={action}
													className="group flex cursor-default select-none items-center rounded-md px-3 py-2 data-[focus]:bg-famedic-dark data-[focus]:text-white"
												>
													<action.icon
														className="h-6 w-6 flex-none text-gray-400 group-data-[focus]:text-white"
														aria-hidden="true"
													/>
													<span className="ml-3 flex-auto truncate">
														{action.name}
													</span>
													<span className="ml-3 flex-none text-xs font-semibold text-gray-400 group-data-[focus]:text-indigo-100">
														<kbd className="font-sans">
															⌘
														</kbd>
														<kbd className="font-sans">
															{action.shortcut}
														</kbd>
													</span>
												</ComboboxOption>
											))}
										</ul>
									</li>
								)}
							</ComboboxOptions>
						)}

						{false && filteredProjects.length === 0 && (
							<div className="px-6 py-14 text-center sm:px-14">
								<FolderIcon
									className="mx-auto h-6 w-6 text-gray-400"
									aria-hidden="true"
								/>
								<p className="mt-4 text-sm text-gray-900">
									We couldn't find any projects with that
									term. Please try again.
								</p>
							</div>
						)}
					</Combobox>
				</DialogPanel>
			</div>
		</Dialog>
	);
}
