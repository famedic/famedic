import clsx from "clsx";

export default function ApplicationLogo(props) {
	const { className, forceLight, forceDark, ...otherProps } = props;
	if (forceLight) {
		return (
			<img
				{...otherProps}
				alt="Famedic"
				className={className}
				src="/images/logo.png"
			/>
		);
	}

	if (forceDark) {
		return (
			<img
				{...otherProps}
				alt="Famedic"
				className={className}
				src="/images/logo-dark.png"
			/>
		);
	}
	return (
		<>
			<img
				{...otherProps}
				alt="Famedic"
				className={clsx("dark:hidden", className)}
				src="/images/logo.png"
			/>{" "}
			<img
				{...otherProps}
				alt="Famedic"
				className={clsx("hidden dark:block", className)}
				src="/images/logo-dark.png"
			/>
		</>
	);
}
