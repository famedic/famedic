import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import {
	Field,
	Label,
	Description,
	ErrorMessage,
} from "@/Components/Catalyst/fieldset";
import { Textarea } from "@/Components/Catalyst/textarea";
import { Checkbox, CheckboxField } from "@/Components/Catalyst/checkbox";
import { Badge } from "@/Components/Catalyst/badge";
import {
	ArrowPathIcon,
	ChatBubbleLeftRightIcon,
	CheckCircleIcon,
	PencilSquareIcon,
} from "@heroicons/react/16/solid";
import Card from "@/Components/Card";

/**
 * Abstract, reusable composer for dev assistance comments and actions.
 *
 * This component handles all dev assistance form interactions including:
 * - Creating new dev assistance requests
 * - Adding comments to existing requests
 * - Resolving/unresolving requests
 *
 * The component automatically adapts its UI and behavior based on props:
 * - Button text changes based on `mark_resolved` state
 * - Form styling changes based on `asCard` prop
 * - Validation and error handling built-in
 *
 * @component
 * @example
 * // Create new dev assistance request (in dialog)
 * <DevAssistanceComposer
 *   route={route('admin.laboratory-purchases.dev-assistance-request.store', {
 *     laboratory_purchase: purchase.id
 *   })}
 *   onSuccess={() => setIsOpen(false)}
 * />
 *
 * @example
 * // Add comment with resolve option (in timeline)
 * <DevAssistanceComposer
 *   route={route('admin.laboratory-purchases.dev-assistance-request.resolved', {
 *     laboratory_purchase: purchase.id,
 *     dev_assistance_request: request.id
 *   })}
 *   showMarkResolved={true}
 *   asCard={true}
 * />
 *
 * @example
 * // Reopen resolved request (in timeline)
 * <DevAssistanceComposer
 *   route={route('admin.laboratory-purchases.dev-assistance-request.unresolved', {
 *     laboratory_purchase: purchase.id,
 *     dev_assistance_request: request.id
 *   })}
 *   description="Explica por qué estás reabriendo esta solicitud"
 *   asCard={true}
 * />
 *
 * @param {Object} props
 * @param {string} props.route - Inertia route for form submission (required)
 * @param {Function} [props.onSuccess] - Callback after successful submission (optional)
 * @param {boolean} [props.showMarkResolved=false] - Show "mark as resolved" checkbox (optional)
 * @param {string} [props.description] - Optional description text shown above textarea (optional)
 * @param {boolean} [props.asCard=false] - Render as card with badge header (for timeline integration) (optional)
 *
 * @returns {JSX.Element} Form component with textarea, optional checkbox, and submit button
 *
 * @note The component uses Inertia's useForm hook and automatically handles:
 * - Form validation and error display
 * - Loading states with spinner
 * - Form reset after successful submission
 * - Scroll preservation during navigation
 */
export default function DevAssistanceComposer({
	route,
	onSuccess,
	showMarkResolved = false,
	description = null,
	asCard = false,
}) {
	// Form state management with Inertia
	const { data, setData, post, processing, errors, reset } = useForm({
		comment: "",
		mark_resolved: false,
	});

	// Handle form submission with Inertia
	const handleSubmit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(route, {
				preserveScroll: true, // Keep scroll position after navigation
				onSuccess: () => {
					reset(); // Clear form data
					onSuccess?.(); // Call parent callback (e.g., close dialog)
				},
			});
		}
	};

	// Form fields with conditional elements
	const formFields = (
		<div className="space-y-4">
			{/* Comment textarea - always present */}
			<Field>
				<Label>Comentario</Label>
				{description && <Description>{description}</Description>}
				<Textarea
					invalid={!!errors.comment}
					value={data.comment}
					onChange={(e) => setData("comment", e.target.value)}
					rows={4}
					placeholder={"Escribe tu comentario..."}
				/>
				{errors.comment && (
					<ErrorMessage>{errors.comment}</ErrorMessage>
				)}
			</Field>

			{/* Resolve checkbox - only shown when showMarkResolved is true */}
			{showMarkResolved && (
				<CheckboxField>
					<Checkbox
						checked={data.mark_resolved}
						onChange={(checked) =>
							setData("mark_resolved", checked)
						}
					/>
					<Label>Marcar como resuelta</Label>
				</CheckboxField>
			)}

			{/* Submit button with dynamic text and icon based on mark_resolved state */}
			<Button type="submit" disabled={processing} className="w-full">
				{data.mark_resolved ? (
					<CheckCircleIcon />
				) : (
					<ChatBubbleLeftRightIcon />
				)}
				{data.mark_resolved
					? "Resolver asistencia técnica"
					: "Agregar comentario"}
				{processing && <ArrowPathIcon className="animate-spin" />}
			</Button>
		</div>
	);

	// Render as card (for timeline integration) or plain form
	if (asCard) {
		return (
			<form onSubmit={handleSubmit}>
				<Card className="space-y-3 overflow-hidden p-3">
					{/* Card header with badge */}
					<div className="flex items-center gap-2">
						<Badge
							color="slate"
							className="text-famedic-darker dark:text-white"
						>
							<PencilSquareIcon className="size-4 fill-slate-400 dark:fill-slate-500" />
							Nuevo comentario
						</Badge>
					</div>
					{formFields}
				</Card>
			</form>
		);
	}

	// Plain form (for dialog usage)
	return <form onSubmit={handleSubmit}>{formFields}</form>;
}
