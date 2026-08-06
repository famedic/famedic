import { router, useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { ArrowPathIcon, MagnifyingGlassIcon } from "@heroicons/react/16/solid";

export default function Header({ urls, meta, onOpenSearch }) {
	const testForm = useForm({});

	return (
		<header className="space-y-4 border-b border-zinc-200 pb-6 dark:border-zinc-700">
			<div className="flex flex-wrap items-start justify-between gap-4">
				<div className="min-w-0 space-y-2">
					<Heading>ActiveCampaign Operations Center</Heading>
					<Text className="max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
						Monitoreo, sincronización y diagnóstico de la integración con
						ActiveCampaign.
					</Text>
					{meta?.generated_at ? (
						<p className="text-[11px] text-zinc-400">
							Actualizado {meta.generated_at}
							{meta.enabled === false ? " · Integración deshabilitada" : ""}
						</p>
					) : null}
				</div>
				<div className="flex flex-wrap items-center gap-2">
					<Button
						outline
						onClick={() =>
							router.reload({
								preserveScroll: true,
								preserveState: false,
							})
						}
					>
						<ArrowPathIcon className="size-4" />
						Refresh
					</Button>
					<Button
						outline
						disabled={testForm.processing}
						onClick={() => testForm.post(urls.testApi, { preserveScroll: true })}
					>
						Test API
					</Button>
					<Button onClick={onOpenSearch}>
						<MagnifyingGlassIcon className="size-4" />
						Buscar contacto
					</Button>
				</div>
			</div>
		</header>
	);
}

export function ContactSearchBar({ urls, open, onClose }) {
	const form = useForm({ action: "search_contact", email: "" });

	if (!open) {
		return null;
	}

	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<form
				className="flex flex-wrap items-end gap-3"
				onSubmit={(e) => {
					e.preventDefault();
					form.post(urls.diagnostic, {
						preserveScroll: true,
						onSuccess: () => onClose?.(),
					});
				}}
			>
				<div className="min-w-[16rem] flex-1">
					<label className="mb-1 block text-[11px] font-medium uppercase tracking-wide text-zinc-500">
						Email del contacto
					</label>
					<Input
						type="email"
						value={form.data.email}
						onChange={(e) => form.setData("email", e.target.value)}
						placeholder="cliente@ejemplo.com"
						required
					/>
				</div>
				<Button type="submit" disabled={form.processing}>
					Buscar
				</Button>
				<Button outline type="button" onClick={onClose}>
					Cerrar
				</Button>
			</form>
		</div>
	);
}
