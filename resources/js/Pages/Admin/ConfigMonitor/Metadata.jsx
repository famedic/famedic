import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { useForm, router } from "@inertiajs/react";
import { useState } from "react";

export default function ConfigMonitorMetadata({ groups }) {
	const groupForm = useForm({
		name: "",
		slug: "",
		sort_order: 0,
	});

	const settingForm = useForm({
		setting_group_id: groups[0]?.id ?? "",
		env_key: "",
		config_key: "",
		label: "",
		description: "",
		is_sensitive: false,
		is_required: false,
		sort_order: 0,
	});

	const [editingGroupId, setEditingGroupId] = useState(null);
	const [editingSettingId, setEditingSettingId] = useState(null);

	const editGroupForm = useForm({
		name: "",
		slug: "",
		sort_order: 0,
	});

	const editSettingForm = useForm({
		setting_group_id: "",
		env_key: "",
		config_key: "",
		label: "",
		description: "",
		is_sensitive: false,
		is_required: false,
		sort_order: 0,
	});

	const submitGroup = (e) => {
		e.preventDefault();
		groupForm.post(route("admin.config-monitor.metadata.groups.store"), {
			preserveScroll: true,
			onSuccess: () => {
				groupForm.reset();
				groupForm.clearErrors();
			},
		});
	};

	const submitSetting = (e) => {
		e.preventDefault();
		settingForm.post(route("admin.config-monitor.metadata.settings.store"), {
			preserveScroll: true,
			onSuccess: () => {
				settingForm.reset();
				settingForm.setData({
					setting_group_id: groups[0]?.id ?? "",
					env_key: "",
					config_key: "",
					label: "",
					description: "",
					is_sensitive: false,
					is_required: false,
					sort_order: 0,
				});
				settingForm.clearErrors();
			},
		});
	};

	const startEditGroup = (g) => {
		setEditingGroupId(g.id);
		editGroupForm.setData({
			name: g.name,
			slug: g.slug,
			sort_order: g.sort_order,
		});
	};

	const saveGroup = (e, groupId) => {
		e.preventDefault();
		editGroupForm.patch(route("admin.config-monitor.metadata.groups.update", groupId), {
			preserveScroll: true,
			onSuccess: () => {
				setEditingGroupId(null);
				editGroupForm.clearErrors();
			},
		});
	};

	const deleteGroup = (groupId) => {
		if (!confirm("¿Eliminar grupo y todas sus claves monitoreadas?")) return;
		router.delete(route("admin.config-monitor.metadata.groups.destroy", groupId), {
			preserveScroll: true,
		});
	};

	const startEditSetting = (s) => {
		setEditingSettingId(s.id);
		editSettingForm.setData({
			setting_group_id: s.setting_group_id,
			env_key: s.env_key,
			config_key: s.config_key,
			label: s.label ?? "",
			description: s.description ?? "",
			is_sensitive: !!s.is_sensitive,
			is_required: !!s.is_required,
			sort_order: s.sort_order,
		});
	};

	const saveSetting = (e, settingId) => {
		e.preventDefault();
		editSettingForm.patch(route("admin.config-monitor.metadata.settings.update", settingId), {
			preserveScroll: true,
			onSuccess: () => {
				setEditingSettingId(null);
				editSettingForm.clearErrors();
			},
		});
	};

	const deleteSetting = (settingId) => {
		if (!confirm("¿Eliminar esta clave monitoreada?")) return;
		router.delete(route("admin.config-monitor.metadata.settings.destroy", settingId), {
			preserveScroll: true,
		});
	};

	return (
		<AdminLayout title="Config Monitor — metadatos">
			<div className="space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Metadatos del Config Monitor</Heading>
						<Text className="mt-2 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
							Aquí solo se guardan grupos y mapeos ENV → config (sin valores reales). Los valores
							viven en .env y en la caché de configuración de Laravel.
						</Text>
					</div>
					<Button href={route("admin.config-monitor.index")} outline>
						Volver al monitor
					</Button>
				</div>

				<section className="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
					<Text className="font-semibold">Nuevo grupo</Text>
					<form onSubmit={submitGroup} className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
						<label className="block space-y-1 text-sm">
							<span className="text-zinc-500">Nombre</span>
							<input
								className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
								value={groupForm.data.name}
								onChange={(e) => groupForm.setData("name", e.target.value)}
								required
							/>
							{groupForm.errors.name ? (
								<span className="text-xs text-red-600">{groupForm.errors.name}</span>
							) : null}
						</label>
						<label className="block space-y-1 text-sm">
							<span className="text-zinc-500">Slug (kebab)</span>
							<input
								className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
								value={groupForm.data.slug}
								onChange={(e) => groupForm.setData("slug", e.target.value)}
								required
							/>
							{groupForm.errors.slug ? (
								<span className="text-xs text-red-600">{groupForm.errors.slug}</span>
							) : null}
						</label>
						<label className="block space-y-1 text-sm">
							<span className="text-zinc-500">Orden</span>
							<input
								type="number"
								className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
								value={groupForm.data.sort_order}
								onChange={(e) => groupForm.setData("sort_order", Number(e.target.value))}
							/>
						</label>
						<div className="flex items-end">
							<Button type="submit" disabled={groupForm.processing}>
								Crear grupo
							</Button>
						</div>
					</form>
				</section>

				<section className="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
					<Text className="font-semibold">Nueva clave monitoreada</Text>
					<form onSubmit={submitSetting} className="mt-3 space-y-3">
						<div className="grid gap-3 lg:grid-cols-2">
							<label className="block space-y-1 text-sm">
								<span className="text-zinc-500">Grupo</span>
								<select
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
									value={settingForm.data.setting_group_id}
									onChange={(e) => settingForm.setData("setting_group_id", Number(e.target.value))}
									required
								>
									{groups.map((g) => (
										<option key={g.id} value={g.id}>
											{g.name}
										</option>
									))}
								</select>
							</label>
							<label className="block space-y-1 text-sm">
								<span className="text-zinc-500">ENV_KEY</span>
								<input
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-800"
									value={settingForm.data.env_key}
									onChange={(e) => settingForm.setData("env_key", e.target.value)}
									placeholder="APP_URL"
									required
								/>
								{settingForm.errors.env_key ? (
									<span className="text-xs text-red-600">{settingForm.errors.env_key}</span>
								) : null}
							</label>
							<label className="block space-y-1 text-sm lg:col-span-2">
								<span className="text-zinc-500">config_key (notación punto)</span>
								<input
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-800"
									value={settingForm.data.config_key}
									onChange={(e) => settingForm.setData("config_key", e.target.value)}
									placeholder="app.url"
									required
								/>
								{settingForm.errors.config_key ? (
									<span className="text-xs text-red-600">{settingForm.errors.config_key}</span>
								) : null}
							</label>
							<label className="block space-y-1 text-sm">
								<span className="text-zinc-500">Etiqueta</span>
								<input
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
									value={settingForm.data.label}
									onChange={(e) => settingForm.setData("label", e.target.value)}
								/>
							</label>
							<label className="block space-y-1 text-sm">
								<span className="text-zinc-500">Orden</span>
								<input
									type="number"
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
									value={settingForm.data.sort_order}
									onChange={(e) => settingForm.setData("sort_order", Number(e.target.value))}
								/>
							</label>
							<label className="flex items-center gap-2 text-sm lg:col-span-2">
								<input
									type="checkbox"
									checked={settingForm.data.is_sensitive}
									onChange={(e) => settingForm.setData("is_sensitive", e.target.checked)}
								/>
								Sensible (ocultar en UI)
							</label>
							<label className="flex items-center gap-2 text-sm lg:col-span-2">
								<input
									type="checkbox"
									checked={settingForm.data.is_required}
									onChange={(e) => settingForm.setData("is_required", e.target.checked)}
								/>
								Requerido
							</label>
							<label className="block space-y-1 text-sm lg:col-span-2">
								<span className="text-zinc-500">Descripción</span>
								<textarea
									className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
									rows={2}
									value={settingForm.data.description}
									onChange={(e) => settingForm.setData("description", e.target.value)}
								/>
							</label>
						</div>
						<Button type="submit" disabled={settingForm.processing || groups.length === 0}>
							Agregar clave
						</Button>
					</form>
				</section>

				{groups.map((g) => (
					<section key={g.id} className="space-y-3">
						<div className="flex flex-wrap items-center justify-between gap-2">
							{editingGroupId === g.id ? (
								<form
									onSubmit={(e) => saveGroup(e, g.id)}
									className="flex flex-wrap items-end gap-2"
								>
									<label className="space-y-1 text-sm">
										<span className="text-zinc-500">Nombre</span>
										<input
											className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
											value={editGroupForm.data.name}
											onChange={(e) => editGroupForm.setData("name", e.target.value)}
										/>
									</label>
									<label className="space-y-1 text-sm">
										<span className="text-zinc-500">Slug</span>
										<input
											className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
											value={editGroupForm.data.slug}
											onChange={(e) => editGroupForm.setData("slug", e.target.value)}
										/>
									</label>
									<label className="space-y-1 text-sm">
										<span className="text-zinc-500">Orden</span>
										<input
											type="number"
											className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
											value={editGroupForm.data.sort_order}
											onChange={(e) => editGroupForm.setData("sort_order", Number(e.target.value))}
										/>
									</label>
									<Button type="submit" disabled={editGroupForm.processing}>
										Guardar
									</Button>
									<Button type="button" outline onClick={() => setEditingGroupId(null)}>
										Cancelar
									</Button>
								</form>
							) : (
								<>
									<Heading level={3}>{g.name}</Heading>
									<div className="flex gap-2">
										<Button type="button" outline onClick={() => startEditGroup(g)}>
											Editar grupo
										</Button>
										<Button type="button" outline onClick={() => deleteGroup(g.id)}>
											Eliminar grupo
										</Button>
									</div>
								</>
							)}
						</div>

						<div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
							<Table className="min-w-[960px] [--gutter:theme(spacing.4)]">
								<TableHead>
									<TableRow>
										<TableHeader>ENV</TableHeader>
										<TableHeader>config</TableHeader>
										<TableHeader>Flags</TableHeader>
										<TableHeader />
									</TableRow>
								</TableHead>
								<TableBody>
									{g.settings?.length ? (
										g.settings.map((s) =>
											editingSettingId === s.id ? (
												<TableRow key={s.id}>
													<TableCell colSpan={4}>
														<form
															onSubmit={(e) => saveSetting(e, s.id)}
															className="grid gap-2 lg:grid-cols-2"
														>
															<label className="text-xs">
																Grupo
																<select
																	className="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
																	value={editSettingForm.data.setting_group_id}
																	onChange={(e) =>
																		editSettingForm.setData(
																			"setting_group_id",
																			Number(e.target.value),
																		)
																	}
																>
																	{groups.map((gg) => (
																		<option key={gg.id} value={gg.id}>
																			{gg.name}
																		</option>
																	))}
																</select>
															</label>
															<label className="text-xs">
																ENV
																<input
																	className="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-800"
																	value={editSettingForm.data.env_key}
																	onChange={(e) =>
																		editSettingForm.setData("env_key", e.target.value)
																	}
																/>
															</label>
															<label className="text-xs lg:col-span-2">
																config_key
																<input
																	className="mt-1 w-full rounded-md border border-zinc-300 bg-white px-2 py-1 font-mono text-sm dark:border-zinc-600 dark:bg-zinc-800"
																	value={editSettingForm.data.config_key}
																	onChange={(e) =>
																		editSettingForm.setData("config_key", e.target.value)
																	}
																/>
															</label>
															<label className="flex items-center gap-2 text-xs">
																<input
																	type="checkbox"
																	checked={editSettingForm.data.is_sensitive}
																	onChange={(e) =>
																		editSettingForm.setData(
																			"is_sensitive",
																			e.target.checked,
																		)
																	}
																/>
																Sensible
															</label>
															<label className="flex items-center gap-2 text-xs">
																<input
																	type="checkbox"
																	checked={editSettingForm.data.is_required}
																	onChange={(e) =>
																		editSettingForm.setData(
																			"is_required",
																			e.target.checked,
																		)
																	}
																/>
																Requerido
															</label>
															<div className="flex gap-2 lg:col-span-2">
																<Button type="submit" disabled={editSettingForm.processing}>
																	Guardar
																</Button>
																<Button
																	type="button"
																	outline
																	onClick={() => setEditingSettingId(null)}
																>
																	Cancelar
																</Button>
															</div>
														</form>
													</TableCell>
												</TableRow>
											) : (
												<TableRow key={s.id}>
													<TableCell className="font-mono text-xs">{s.env_key}</TableCell>
													<TableCell className="font-mono text-xs">{s.config_key}</TableCell>
													<TableCell className="text-xs">
														{s.is_sensitive ? "sensible " : ""}
														{s.is_required ? "requerido" : "opcional"}
													</TableCell>
													<TableCell className="text-right">
														<Button type="button" outline onClick={() => startEditSetting(s)}>
															Editar
														</Button>{" "}
														<Button type="button" outline onClick={() => deleteSetting(s.id)}>
															Eliminar
														</Button>
													</TableCell>
												</TableRow>
											),
										)
									) : (
										<TableRow>
											<TableCell colSpan={4}>
												<Text className="text-sm text-zinc-500">Sin claves en este grupo.</Text>
											</TableCell>
										</TableRow>
									)}
								</TableBody>
							</Table>
						</div>
					</section>
				))}
			</div>
		</AdminLayout>
	);
}
