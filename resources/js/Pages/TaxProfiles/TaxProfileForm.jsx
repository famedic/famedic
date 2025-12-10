import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import { Description, Field, Label } from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Button } from "@/Components/Catalyst/button";
import { usePage, useForm, router } from "@inertiajs/react";
import { ErrorMessage } from "@/Components/Catalyst/fieldset";
import { useEffect, useState } from "react";
import {
	ArrowPathIcon,
	DocumentTextIcon,
	CheckCircleIcon,
	ExclamationTriangleIcon,
	PencilIcon,
} from "@heroicons/react/24/solid";
import {
	Listbox,
	ListboxLabel,
	ListboxOption,
} from "@/Components/Catalyst/listbox";

// Definimos los pasos del proceso
const STEPS = {
	UPLOAD: 1,
	REVIEW: 2,
	CONFIRM: 3,
};

export default function TaxProfileForm({ isOpen }) {
	const { taxProfile, taxRegimes, cfdiUses } = usePage().props;

	const [cachedTaxRegimes, setCachedTaxRegimes] = useState(taxRegimes);
	const [cachedCfdiUses, setCachedCfdiUses] = useState(cfdiUses);
	const [cachedEditMode, setCachedEditMode] = useState(
		route().current("tax-profiles.edit"),
	);
	const [cachedTaxProfile, setCachedTaxProfile] = useState(taxProfile || null);
	const [extractedData, setExtractedData] = useState(null);
	const [processingPdf, setProcessingPdf] = useState(false);
	const [uploadedFile, setUploadedFile] = useState(null);
	const [infoMessage, setInfoMessage] = useState(null);
	const [currentStep, setCurrentStep] = useState(null);
	const [uploadProgress, setUploadProgress] = useState(0);
	const [activeStep, setActiveStep] = useState(STEPS.UPLOAD);
	const [isEditing, setIsEditing] = useState(false);

	const resetFormData = (taxProfile) => ({
		name: taxProfile?.name ?? "",
		rfc: taxProfile?.rfc ?? "",
		zipcode: taxProfile?.zipcode ?? "",
		tax_regime: taxProfile?.tax_regime ?? null,
		cfdi_use: taxProfile?.cfdi_use ?? null,
		fiscal_certificate: null,
		confirm_data: false,
	});

	const { data, setData, post, transform, processing, errors, setError, clearErrors } = useForm(
		resetFormData(taxProfile || {}),
	);

	useEffect(() => {
		if (isOpen) {
			const isEditMode = route().current("tax-profiles.edit") ?? false;
			setCachedTaxRegimes(taxRegimes || {});
			setCachedCfdiUses(cfdiUses || {});
			setCachedTaxProfile(taxProfile || null);
			setCachedEditMode(isEditMode);
			setData(resetFormData(taxProfile || {}));
			setExtractedData(null);
			setUploadedFile(null);
			setInfoMessage(null);
			setCurrentStep(null);
			setUploadProgress(0);
			setActiveStep(STEPS.UPLOAD);
			setIsEditing(false);

			if (isEditMode && taxProfile) {
				setActiveStep(STEPS.REVIEW);
				setIsEditing(true);
			}
		}
	}, [isOpen]);

	transform((data) => ({
		...data,
		...(cachedEditMode && { _method: "put" }),
	}));

	// Función para extraer datos del PDF
	const handleFileUpload = async (e) => {
		const file = e.target.files[0];
		if (!file) return;

		if (file.type !== 'application/pdf') {
			setError('fiscal_certificate', 'Solo se aceptan archivos PDF');
			return;
		}

		if (file.size > 5 * 1024 * 1024) {
			setError('fiscal_certificate', 'El archivo no debe superar 5MB');
			return;
		}

		// Reset estados
		setProcessingPdf(true);
		setCurrentStep('Validando archivo...');
		setUploadProgress(10);
		setInfoMessage(null);
		clearErrors('fiscal_certificate');

		try {
			// Paso 1: Preparar datos
			setCurrentStep('Preparando para enviar...');
			setUploadProgress(20);
			await new Promise(resolve => setTimeout(resolve, 300));

			const formData = new FormData();
			formData.append('fiscal_certificate', file);

			// Obtener CSRF token
			const metaTag = document.querySelector('meta[name="csrf-token"]');
			if (!metaTag) {
				throw new Error('CSRF_TOKEN_NOT_FOUND');
			}
			const csrfToken = metaTag.getAttribute('content');
			formData.append('_token', csrfToken);

			// Paso 2: Enviar al servidor
			setCurrentStep('Enviando archivo...');
			setUploadProgress(40);

			const controller = new AbortController();
			const timeoutId = setTimeout(() => controller.abort(), 30000);

			const response = await fetch(route('tax-profiles.extract-data'), {
				method: 'POST',
				body: formData,
				credentials: 'include',
				headers: {
					'X-Requested-With': 'XMLHttpRequest',
					'Accept': 'application/json',
					'X-CSRF-TOKEN': csrfToken,
				},
				signal: controller.signal,
			});

			clearTimeout(timeoutId);

			// Leer respuesta
			const responseText = await response.text();

			// Parsear JSON
			let result;
			try {
				result = JSON.parse(responseText);
			} catch (jsonError) {
				console.error('Error parseando JSON:', jsonError);
				throw new Error('INVALID_JSON_RESPONSE');
			}

			// Verificar resultado
			setUploadProgress(80);
			setCurrentStep('Extrayendo información...');

			if (response.ok && result.success) {
				setUploadProgress(100);
				setCurrentStep('¡Completado!');

				// Guardar datos y archivo
				setExtractedData(result.data);
				setUploadedFile(file);
				setData('fiscal_certificate', file);

				// Rellenar campos
				if (result.data.rfc) setData('rfc', result.data.rfc);
				if (result.data.razon_social || result.data.nombre) {
					setData('name', result.data.razon_social || result.data.nombre);
				}
				if (result.data.codigo_postal) setData('zipcode', result.data.codigo_postal);
				if (result.data.regimen_fiscal) {
					const regimenEncontrado = encontrarRegimenPorTexto(result.data.regimen_fiscal);
					if (regimenEncontrado) setData('tax_regime', regimenEncontrado);
				}

				clearErrors();

				// Mostrar mensaje de éxito
				setInfoMessage({
					type: 'success',
					message: 'Archivo procesado exitosamente. Revisa la información extraída.',
				});

				// Avanzar al paso de revisión después de 1 segundo
				setTimeout(() => {
					setCurrentStep(null);
					setUploadProgress(0);
					setActiveStep(STEPS.REVIEW);
				}, 1000);

			} else {
				throw new Error(result?.message || `Error ${response.status}`);
			}

		} catch (error) {
			console.error('Error en extracción:', error);

			// Fallback: subir archivo sin extracción
			setData('fiscal_certificate', file);
			setUploadedFile(file);
			setActiveStep(STEPS.REVIEW);

			setInfoMessage({
				type: 'warning',
				message: 'Archivo subido. Complete los datos manualmente.',
			});

		} finally {
			setProcessingPdf(false);
			setCurrentStep(null);
			setUploadProgress(0);
		}
	};

	const encontrarRegimenPorTexto = (textoRegimen) => {
		if (!textoRegimen || !cachedTaxRegimes) return null;

		const textoLower = textoRegimen.toLowerCase();

		// Mapeo de términos comunes
		const mapeoTerminos = {
			'incorporación': '601',
			'sueldos': '605',
			'salarios': '605',
			'empresarial': '606',
			'arrendamiento': '606',
			'moral': '603',
			'enajenación': '606',
			'extranjero': '610',
			'intereses': '608',
			'dividendos': '609',
			'general': '601',
		};

		// Buscar por términos
		for (const [termino, clave] of Object.entries(mapeoTerminos)) {
			if (textoLower.includes(termino) && cachedTaxRegimes[clave]) {
				return clave;
			}
		}

		// Buscar por nombre de régimen
		for (const [key, regimen] of Object.entries(cachedTaxRegimes)) {
			const regimenLower = regimen.name.toLowerCase();
			if (textoLower.includes(regimenLower) || regimenLower.includes(textoLower)) {
				return key;
			}
		}

		return null;
	};

	const handleNextStep = () => {
		if (activeStep === STEPS.REVIEW) {
			// Validar campos requeridos antes de avanzar
			if (!data.name || !data.rfc || !data.zipcode || !data.tax_regime || !data.cfdi_use) {
				setInfoMessage({
					type: 'error',
					message: 'Complete todos los campos requeridos antes de continuar.',
				});
				return;
			}
			setActiveStep(STEPS.CONFIRM);
		}
	};

	const handlePrevStep = () => {
		if (activeStep === STEPS.REVIEW) {
			setActiveStep(STEPS.UPLOAD);
			setExtractedData(null);
			setUploadedFile(null);
			setData('fiscal_certificate', null);
		} else if (activeStep === STEPS.CONFIRM) {
			setActiveStep(STEPS.REVIEW);
		}
	};

	// En tu componente React, actualiza la función submit:
	const submit = (e) => {
		e.preventDefault();

		// Validar que se haya subido el archivo
		if (!data.fiscal_certificate) {
			setError('fiscal_certificate', 'Debe subir una constancia fiscal');
			return;
		}

		// Validar confirmación solo si se extrajeron datos
		if (extractedData && !data.confirm_data) {
			setError('confirm_data', 'Debe confirmar que los datos extraídos son correctos');
			return;
		}

		// Validar campos requeridos
		if (!data.tax_regime || !data.cfdi_use) {
			setError('tax_regime', 'Debe seleccionar régimen fiscal y uso CFDI');
			return;
		}

		// Preparar datos para enviar
		const formData = new FormData();

		// Agregar campos básicos
		formData.append('name', data.name);
		formData.append('rfc', data.rfc);
		formData.append('zipcode', data.zipcode);
		formData.append('tax_regime', data.tax_regime);
		formData.append('cfdi_use', data.cfdi_use);
		formData.append('fiscal_certificate', data.fiscal_certificate);
		formData.append('confirm_data', data.confirm_data ? '1' : '0');

		// Agregar datos extraídos si existen
		if (extractedData) {
			formData.append('extracted_data', JSON.stringify({
				razon_social: extractedData.razon_social || extractedData.nombre || data.name,
				tipo_persona: extractedData.tipo_persona,
				tipo_persona_confianza: extractedData.tipo_persona_confianza,
				fecha_emision: extractedData.fecha_emision,
				estatus_sat: extractedData.estatus_sat,
				regimen_fiscal: extractedData.regimen_fiscal,
				codigo_postal: extractedData.codigo_postal,
				// Agregar otros campos si los tienes
				domicilio_fiscal: extractedData.domicilio_fiscal,
				actividades_economicas: extractedData.actividades_economicas,
				fecha_inscripcion: extractedData.fecha_inscripcion,
			}));
		}

		// Agregar token CSRF
		const metaTag = document.querySelector('meta[name="csrf-token"]');
		if (metaTag) {
			formData.append('_token', metaTag.getAttribute('content'));
		}

		if (!processing) {
			if (cachedEditMode) {
				formData.append('_method', 'PUT');

				fetch(route("tax-profiles.update", { tax_profile: cachedTaxProfile }), {
					method: 'POST',
					body: formData,
					credentials: 'include',
				})
					.then(response => {
						if (response.redirected) {
							window.location.href = response.url;
						}
						return response.json();
					})
					.catch(error => {
						console.error('Error:', error);
						setInfoMessage({
							type: 'error',
							message: 'Error al guardar el perfil fiscal',
						});
					});
			} else {
				fetch(route("tax-profiles.store"), {
					method: 'POST',
					body: formData,
					credentials: 'include',
				})
					.then(response => {
						if (response.redirected) {
							window.location.href = response.url;
						}
						return response.json();
					})
					.catch(error => {
						console.error('Error:', error);
						setInfoMessage({
							type: 'error',
							message: 'Error al guardar el perfil fiscal',
						});
					});
			}
		}
	};

	const closeDialog = () => {
		router.get(
			route("tax-profiles.index"),
			{},
			{ preserveState: true, preserveScroll: true },
		);
	};

	// Componente para mostrar el progreso de pasos
	const StepIndicator = ({ step, label, isActive, isCompleted }) => (
		<div className="flex flex-col items-center">
			<div className={`flex items-center justify-center w-10 h-10 rounded-full border-2 ${isCompleted ? 'bg-green-100 border-green-600 text-green-600' : isActive ? 'bg-blue-100 border-blue-600 text-blue-600' : 'bg-gray-100 border-gray-300 text-gray-400'}`}>
				{isCompleted ? (
					<CheckCircleIcon className="w-6 h-6" />
				) : (
					<span className="font-semibold">{step}</span>
				)}
			</div>
			<span className={`mt-2 text-sm font-medium ${isActive || isCompleted ? 'text-gray-900' : 'text-gray-500'}`}>
				{label}
			</span>
		</div>
	);

	// Componente Label personalizado para usar fuera de Field
	const SimpleLabel = ({ children, className = "" }) => (
		<div className={`text-sm font-medium text-gray-700 ${className}`}>
			{children}
		</div>
	);

	// Paso 1: Subir archivo
	const renderUploadStep = () => (
		<>
			<DialogTitle>
				{cachedEditMode ? "Actualizar perfil fiscal" : "Nuevo perfil fiscal"}
			</DialogTitle>
			<DialogDescription>
				Sube tu constancia de situación fiscal para comenzar
			</DialogDescription>

			<DialogBody className="space-y-6">
				<Field>
					<Label>Constancia de Situación Fiscal</Label>
					<Description>
						Sube el archivo PDF de tu constancia (máximo 5MB).
						Debe ser emitida en los últimos 3 meses.
					</Description>

					<div className="space-y-4">
						<div className="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-blue-500 transition-colors">
							<DocumentTextIcon className="mx-auto h-12 w-12 text-gray-400" />
							<div className="mt-4">
								<label className="cursor-pointer">
									<span className="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
										Seleccionar archivo PDF
										<input
											type="file"
											className="hidden"
											accept="application/pdf"
											onChange={handleFileUpload}
											disabled={processingPdf}
										/>
									</span>
									<p className="mt-2 text-sm text-gray-600">
										{uploadedFile ? uploadedFile.name : 'Arrastra o haz clic para seleccionar'}
									</p>
								</label>
							</div>
						</div>

						{processingPdf && (
							<div className="space-y-2">
								<div className="w-full bg-gray-200 rounded-full h-2.5">
									<div
										className="bg-blue-600 h-2.5 rounded-full transition-all duration-300"
										style={{ width: `${uploadProgress}%` }}
									></div>
								</div>
								<div className="flex justify-between text-sm text-gray-600">
									<span>{currentStep || 'Procesando...'}</span>
									<span>{uploadProgress}%</span>
								</div>
							</div>
						)}

						{errors.fiscal_certificate && (
							<ErrorMessage>{errors.fiscal_certificate}</ErrorMessage>
						)}
					</div>
				</Field>

				<div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
					<h4 className="font-semibold text-blue-800 mb-2">
						¿Qué haremos con tu archivo?
					</h4>
					<ul className="text-sm text-blue-700 space-y-1">
						<li>✓ Extraeremos automáticamente tu RFC y datos fiscales</li>
						<li>✓ Validaremos que la constancia sea reciente</li>
						<li>✓ Te mostraremos la información para que la revises</li>
						<li>✓ Podrás editar cualquier dato si es necesario</li>
					</ul>
				</div>
			</DialogBody>

			<DialogActions>
				<Button
					autoFocus
					dusk="cancel"
					plain
					type="button"
					onClick={closeDialog}
					disabled={processingPdf}
				>
					Cancelar
				</Button>
				<Button
					type="button"
					disabled={!uploadedFile || processingPdf}
					onClick={() => uploadedFile && setActiveStep(STEPS.REVIEW)}
				>
					{processingPdf ? 'Procesando...' : 'Continuar'}
					{processingPdf && <ArrowPathIcon className="ml-2 h-4 w-4 animate-spin" />}
				</Button>
			</DialogActions>
		</>
	);

	// Paso 2: Revisar y editar información
	const renderReviewStep = () => (
		<>
			<DialogTitle>
				Revisa y completa tu información
			</DialogTitle>
			<DialogDescription>
				Verifica los datos extraídos y completa los campos faltantes
			</DialogDescription>

			<DialogBody className="space-y-6">
				{infoMessage && (
					<div className={`rounded-lg p-4 ${infoMessage.type === 'success' ? 'bg-green-50 border border-green-200' : 'bg-yellow-50 border border-yellow-200'}`}>
						<div className="flex items-center">
							{infoMessage.type === 'success' ? (
								<CheckCircleIcon className="h-5 w-5 text-green-400 mr-2" />
							) : (
								<ExclamationTriangleIcon className="h-5 w-5 text-yellow-400 mr-2" />
							)}
							<span className="font-medium">{infoMessage.message}</span>
						</div>
					</div>
				)}

				{extractedData && (
					<div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
						<h4 className="font-semibold text-blue-800 mb-2 flex items-center">
							<DocumentTextIcon className="h-5 w-5 mr-2" />
							Resumen de datos extraídos
						</h4>
						<div className="grid grid-cols-2 gap-3 text-sm">
							<div>
								<SimpleLabel>RFC:</SimpleLabel>
								<div className="font-mono">{extractedData.rfc || 'No detectado'}</div>
							</div>
							<div>
								<SimpleLabel>Régimen:</SimpleLabel>
								<div>{extractedData.regimen_fiscal || 'No detectado'}</div>
							</div>
						</div>
						<button
							type="button"
							onClick={() => setIsEditing(!isEditing)}
							className="mt-3 text-sm text-blue-600 hover:text-blue-800 flex items-center"
						>
							<PencilIcon className="h-4 w-4 mr-1" />
							{isEditing ? 'Ver resumen' : 'Editar datos'}
						</button>
					</div>
				)}

				{isEditing || !extractedData ? (
					<>
						<Field>
							<Label>Nombre o Razón Social *</Label>
							<Input
								dusk="name"
								required
								invalid={!!errors.name}
								value={data.name}
								onChange={(e) => setData("name", e.target.value)}
								type="text"
								autoComplete="given-name"
							/>
							{errors.name && <ErrorMessage>{errors.name}</ErrorMessage>}
						</Field>

						<Field>
							<Label>RFC *</Label>
							<Input
								dusk="rfc"
								required
								invalid={!!errors.rfc}
								value={data.rfc}
								onChange={(e) => setData("rfc", e.target.value)}
								type="text"
							/>
							{errors.rfc && <ErrorMessage>{errors.rfc}</ErrorMessage>}
						</Field>

						<Field>
							<Label>Código postal *</Label>
							<Input
								dusk="zipcode"
								required
								invalid={!!errors.zipcode}
								type="text"
								autoComplete="postal-code"
								value={data.zipcode}
								onChange={(e) => setData("zipcode", e.target.value)}
							/>
							{errors.zipcode && <ErrorMessage>{errors.zipcode}</ErrorMessage>}
						</Field>

						<Field>
							<Label>Régimen fiscal *</Label>
							<Listbox
								invalid={!!errors.tax_regime}
								placeholder="Selecciona un régimen fiscal"
								value={data.tax_regime}
								onChange={(value) => {
									setData("tax_regime", value);
									setData("cfdi_use", null);
								}}
							>
								{cachedTaxRegimes && Object.keys(cachedTaxRegimes).map((key) => (
									<ListboxOption key={key} value={key}>
										<ListboxLabel>{`${key} - ${cachedTaxRegimes[key].name}`}</ListboxLabel>
									</ListboxOption>
								))}
							</Listbox>
							{errors.tax_regime && <ErrorMessage>{errors.tax_regime}</ErrorMessage>}
						</Field>

						<Field disabled={!data.tax_regime}>
							<Label>Uso CFDI *</Label>
							<Listbox
								invalid={!!errors.cfdi_use}
								placeholder={data.tax_regime ? "Selecciona un uso de CFDI" : "Primero selecciona un régimen fiscal"}
								value={data.cfdi_use}
								onChange={(value) => setData("cfdi_use", value)}
								disabled={!data.tax_regime}
							>
								{cachedCfdiUses && data.tax_regime &&
									Object.entries(cachedCfdiUses)
										.filter(([key]) => cachedTaxRegimes[data.tax_regime].uses.includes(key))
										.map(([key, value]) => (
											<ListboxOption key={key} value={key}>
												<ListboxLabel>{`${key} - ${value}`}</ListboxLabel>
											</ListboxOption>
										))
								}
							</Listbox>
							{errors.cfdi_use && <ErrorMessage>{errors.cfdi_use}</ErrorMessage>}
						</Field>
					</>
				) : (
					<div className="space-y-4">
						<div className="grid grid-cols-2 gap-4">
							<div className="bg-gray-50 p-4 rounded-lg">
								<SimpleLabel>RFC</SimpleLabel>
								<div className="font-mono text-lg">{data.rfc || 'No ingresado'}</div>
							</div>
							<div className="bg-gray-50 p-4 rounded-lg">
								<SimpleLabel>Código Postal</SimpleLabel>
								<div className="text-lg">{data.zipcode || 'No ingresado'}</div>
							</div>
						</div>
						<div className="bg-gray-50 p-4 rounded-lg">
							<SimpleLabel>Régimen Fiscal</SimpleLabel>
							<div className="text-lg">
								{data.tax_regime && cachedTaxRegimes[data.tax_regime]
									? `${data.tax_regime} - ${cachedTaxRegimes[data.tax_regime].name}`
									: 'No seleccionado'
								}
							</div>
						</div>
						<div className="bg-gray-50 p-4 rounded-lg">
							<SimpleLabel>Uso CFDI</SimpleLabel>
							<div className="text-lg">
								{data.cfdi_use && cachedCfdiUses[data.cfdi_use]
									? `${data.cfdi_use} - ${cachedCfdiUses[data.cfdi_use]}`
									: 'No seleccionado'
								}
							</div>
						</div>
					</div>
				)}
			</DialogBody>

			<DialogActions>
				<Button
					plain
					type="button"
					onClick={handlePrevStep}
				>
					Subir otro documento
				</Button>
				<div className="flex gap-2">
					<Button
						plain
						type="button"
						onClick={() => setIsEditing(!isEditing)}
					>
						{isEditing ? 'Ver resumen' : 'Editar datos'}
					</Button>
					<Button
						type="button"
						onClick={handleNextStep}
					>
						Continuar
					</Button>
				</div>
			</DialogActions>
		</>
	);

	// Paso 3: Confirmar y guardar
	const renderConfirmStep = () => (
		<>
			<DialogTitle>
				Confirma tu información
			</DialogTitle>
			<DialogDescription>
				Verifica que todos los datos sean correctos antes de guardar
			</DialogDescription>

			<DialogBody className="space-y-6">
				<div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
					<div className="flex">
						<ExclamationTriangleIcon className="h-5 w-5 text-yellow-400 mr-2 flex-shrink-0" />
						<div>
							<h4 className="font-semibold text-yellow-800">¡Importante!</h4>
							<p className="text-sm text-yellow-700 mt-1">
								Los datos que confirmes se utilizarán para emitir tus facturas.
								Asegúrate de que sean correctos, ya que errores podrían afectar
								la validez fiscal de tus compras.
							</p>
						</div>
					</div>
				</div>

				<div className="bg-gray-50 border border-gray-200 rounded-lg p-6">
					<h4 className="font-semibold text-gray-800 mb-4">Resumen de tu perfil fiscal</h4>
					<div className="space-y-4">
						<div className="grid grid-cols-2 gap-4">
							<div>
								<SimpleLabel className="text-gray-500">RFC</SimpleLabel>
								<div className="font-mono">{data.rfc}</div>
							</div>
							<div>
								<SimpleLabel className="text-gray-500">Nombre/Razón Social</SimpleLabel>
								<div className="font-medium">{data.name}</div>
							</div>
						</div>
						<div className="grid grid-cols-2 gap-4">
							<div>
								<SimpleLabel className="text-gray-500">Código Postal</SimpleLabel>
								<div>{data.zipcode}</div>
							</div>
							<div>
								<SimpleLabel className="text-gray-500">Régimen Fiscal</SimpleLabel>
								<div>{cachedTaxRegimes[data.tax_regime]?.name}</div>
							</div>
						</div>
						<div>
							<SimpleLabel className="text-gray-500">Uso CFDI</SimpleLabel>
							<div>{cachedCfdiUses[data.cfdi_use]}</div>
						</div>
					</div>
				</div>

				{extractedData && (
					<div className="border-t pt-4">
						<label className="flex items-start space-x-3">
							<input
								type="checkbox"
								checked={data.confirm_data}
								onChange={(e) => {
									setData('confirm_data', e.target.checked);
									clearErrors('confirm_data');
								}}
								className="mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
							/>
							<div>
								<span className="font-medium text-gray-900 text-white">
									Confirmo que los datos extraídos de mi constancia son correctos
								</span>
								<p className="text-sm text-gray-500 mt-1">
									He verificado que toda la información coincide con mi constancia
									de situación fiscal y está actualizada.
								</p>
								{errors.confirm_data && (
									<p className="mt-1 text-sm text-red-600">{errors.confirm_data}</p>
								)}
							</div>
						</label>
					</div>
				)}
			</DialogBody>

			<DialogActions>
				<Button
					plain
					type="button"
					onClick={handlePrevStep}
				>
					Volver a editar
				</Button>
				<div className="flex gap-2">
					<Button
						dusk="saveTaxProfile"
						type="submit"
						disabled={processing || (extractedData && !data.confirm_data)}
					>
						{processing ? 'Guardando...' : 'Guardar perfil fiscal'}
						{processing && <ArrowPathIcon className="ml-2 h-4 w-4 animate-spin" />}
					</Button>
				</div>
			</DialogActions>
		</>
	);

	// Renderizar contenido basado en el paso activo
	const renderContent = () => {
		switch (activeStep) {
			case STEPS.UPLOAD:
				return renderUploadStep();
			case STEPS.REVIEW:
				return renderReviewStep();
			case STEPS.CONFIRM:
				return renderConfirmStep();
			default:
				return renderUploadStep();
		}
	};

	return (
		<Dialog open={isOpen} onClose={closeDialog}>
			<form dusk="taxProfileForm" onSubmit={activeStep === STEPS.CONFIRM ? submit : (e) => e.preventDefault()}>
				{/* Indicador de pasos */}
				<div className="px-6 pt-6">
					<div className="flex justify-between items-center mb-6">
						<StepIndicator
							step="1"
							label="Subir archivo"
							isActive={activeStep === STEPS.UPLOAD}
							isCompleted={activeStep > STEPS.UPLOAD}
						/>
						<div className="flex-1 h-0.5 mx-4 bg-gray-200"></div>
						<StepIndicator
							step="2"
							label="Revisar datos"
							isActive={activeStep === STEPS.REVIEW}
							isCompleted={activeStep > STEPS.REVIEW}
						/>
						<div className="flex-1 h-0.5 mx-4 bg-gray-200"></div>
						<StepIndicator
							step="3"
							label="Confirmar"
							isActive={activeStep === STEPS.CONFIRM}
							isCompleted={false}
						/>
					</div>
				</div>

				{renderContent()}
			</form>
		</Dialog>
	);
}