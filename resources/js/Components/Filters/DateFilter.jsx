import { Input, InputGroup } from "@/Components/Catalyst/input";
import { CalendarIcon } from "@heroicons/react/16/solid";
import { ErrorMessage, Field, Label } from "@/Components/Catalyst/fieldset";

export default function DateFilter({ label, value, onChange, error }) {
	return (
		<Field>
			<Label>{label}</Label>
			<InputGroup>
				<CalendarIcon />
				<Input
					type="date"
					value={value}
					onChange={(e) => onChange(e.target.value)}
				/>
			</InputGroup>
			{error && <ErrorMessage className="mt-3">{error}</ErrorMessage>}
		</Field>
	);
}
